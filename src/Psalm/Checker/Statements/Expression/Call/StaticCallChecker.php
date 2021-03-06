<?php
namespace Psalm\Checker\Statements\Expression\Call;

use PhpParser;
use Psalm\Checker\ClassLikeChecker;
use Psalm\Checker\FunctionLikeChecker;
use Psalm\Checker\MethodChecker;
use Psalm\Checker\Statements\ExpressionChecker;
use Psalm\Checker\StatementsChecker;
use Psalm\CodeLocation;
use Psalm\Config;
use Psalm\Context;
use Psalm\FileManipulation\FileManipulationBuffer;
use Psalm\Issue\DeprecatedClass;
use Psalm\Issue\InvalidStringClass;
use Psalm\Issue\ParentNotFound;
use Psalm\Issue\UndefinedClass;
use Psalm\Issue\UndefinedMethod;
use Psalm\IssueBuffer;
use Psalm\Type;
use Psalm\Type\Atomic\TNamedObject;

class StaticCallChecker extends \Psalm\Checker\Statements\Expression\CallChecker
{
    /**
     * @param   StatementsChecker               $statements_checker
     * @param   PhpParser\Node\Expr\StaticCall  $stmt
     * @param   Context                         $context
     *
     * @return  false|null
     */
    public static function analyze(
        StatementsChecker $statements_checker,
        PhpParser\Node\Expr\StaticCall $stmt,
        Context $context
    ) {
        $method_id = null;

        $lhs_type = null;

        $file_checker = $statements_checker->getFileChecker();
        $project_checker = $file_checker->project_checker;
        $codebase = $project_checker->codebase;
        $source = $statements_checker->getSource();

        $stmt->inferredType = null;

        $config = $project_checker->config;

        if ($stmt->class instanceof PhpParser\Node\Name) {
            $fq_class_name = null;

            if (count($stmt->class->parts) === 1
                && in_array(strtolower($stmt->class->parts[0]), ['self', 'static', 'parent'], true)
            ) {
                if ($stmt->class->parts[0] === 'parent') {
                    $child_fq_class_name = $context->self;

                    $class_storage = $child_fq_class_name
                        ? $project_checker->classlike_storage_provider->get($child_fq_class_name)
                        : null;

                    if (!$class_storage || !$class_storage->parent_classes) {
                        if (IssueBuffer::accepts(
                            new ParentNotFound(
                                'Cannot call method on parent as this class does not extend another',
                                new CodeLocation($statements_checker->getSource(), $stmt)
                            ),
                            $statements_checker->getSuppressedIssues()
                        )) {
                            return false;
                        }

                        return;
                    }

                    $fq_class_name = reset($class_storage->parent_classes);

                    $class_storage = $project_checker->classlike_storage_provider->get($fq_class_name);

                    $fq_class_name = $class_storage->name;

                    if ($stmt->name instanceof PhpParser\Node\Identifier
                        && $class_storage->user_defined
                        && ($context->collect_mutations || $context->collect_initializations)
                    ) {
                        $method_id = $fq_class_name . '::' . strtolower($stmt->name->name);

                        $appearing_method_id = $codebase->getAppearingMethodId($method_id);

                        if (!$appearing_method_id) {
                            if (IssueBuffer::accepts(
                                new UndefinedMethod(
                                    'Method ' . $method_id . ' does not exist',
                                    new CodeLocation($statements_checker->getSource(), $stmt),
                                    $method_id
                                ),
                                $statements_checker->getSuppressedIssues()
                            )) {
                                return false;
                            }

                            return;
                        }

                        list($appearing_method_class_name) = explode('::', $appearing_method_id);

                        $old_context_include_location = $context->include_location;
                        $old_self = $context->self;
                        $context->include_location = new CodeLocation($statements_checker->getSource(), $stmt);
                        $context->self = $appearing_method_class_name;

                        if ($context->collect_mutations) {
                            $file_checker->getMethodMutations($method_id, $context);
                        } else {
                            // collecting initializations
                            $local_vars_in_scope = [];
                            $local_vars_possibly_in_scope = [];

                            foreach ($context->vars_in_scope as $var => $_) {
                                if (strpos($var, '$this->') !== 0 && $var !== '$this') {
                                    $local_vars_in_scope[$var] = $context->vars_in_scope[$var];
                                }
                            }

                            foreach ($context->vars_possibly_in_scope as $var => $_) {
                                if (strpos($var, '$this->') !== 0 && $var !== '$this') {
                                    $local_vars_possibly_in_scope[$var] = $context->vars_possibly_in_scope[$var];
                                }
                            }

                            if (!isset($context->initialized_methods[$method_id])) {
                                if ($context->initialized_methods === null) {
                                    $context->initialized_methods = [];
                                }

                                $context->initialized_methods[$method_id] = true;

                                $file_checker->getMethodMutations($method_id, $context);

                                foreach ($local_vars_in_scope as $var => $type) {
                                    $context->vars_in_scope[$var] = $type;
                                }

                                foreach ($local_vars_possibly_in_scope as $var => $type) {
                                    $context->vars_possibly_in_scope[$var] = $type;
                                }
                            }
                        }

                        $context->include_location = $old_context_include_location;
                        $context->self = $old_self;

                        if (isset($context->vars_in_scope['$this']) && $old_self) {
                            $context->vars_in_scope['$this'] = Type::parseString($old_self);
                        }
                    }
                } elseif ($context->self) {
                    if ($stmt->class->parts[0] === 'static' && isset($context->vars_in_scope['$this'])) {
                        $fq_class_name = (string) $context->vars_in_scope['$this'];
                        $lhs_type = clone $context->vars_in_scope['$this'];
                    } else {
                        $fq_class_name = $context->self;
                    }
                } else {
                    $namespace = $statements_checker->getNamespace()
                        ? $statements_checker->getNamespace() . '\\'
                        : '';

                    $fq_class_name = $namespace . $statements_checker->getClassName();
                }

                if ($context->isPhantomClass($fq_class_name)) {
                    return null;
                }
            } elseif ($context->check_classes) {
                $aliases = $statements_checker->getAliases();

                if ($context->calling_method_id
                    && !$stmt->class instanceof PhpParser\Node\Name\FullyQualified
                ) {
                    $codebase->file_reference_provider->addReferenceToClassMethod(
                        $context->calling_method_id,
                        'use:' . $stmt->class->parts[0] . ':' . \md5($statements_checker->getFilePath())
                    );
                }

                $fq_class_name = ClassLikeChecker::getFQCLNFromNameObject(
                    $stmt->class,
                    $aliases
                );

                if ($context->isPhantomClass($fq_class_name)) {
                    return null;
                }

                $does_class_exist = false;

                if ($context->self) {
                    $self_storage = $project_checker->classlike_storage_provider->get($context->self);

                    if (isset($self_storage->used_traits[strtolower($fq_class_name)])) {
                        $fq_class_name = $context->self;
                        $does_class_exist = true;
                    }
                }

                if (!$does_class_exist) {
                    $does_class_exist = ClassLikeChecker::checkFullyQualifiedClassLikeName(
                        $statements_checker,
                        $fq_class_name,
                        new CodeLocation($source, $stmt->class),
                        $statements_checker->getSuppressedIssues(),
                        false
                    );
                }

                if (!$does_class_exist) {
                    return $does_class_exist;
                }
            }

            if ($codebase->server_mode && $fq_class_name) {
                $codebase->analyzer->addNodeReference(
                    $statements_checker->getFilePath(),
                    $stmt->class,
                    $fq_class_name
                );
            }

            if ($fq_class_name && !$lhs_type) {
                $lhs_type = new Type\Union([new TNamedObject($fq_class_name)]);
            }
        } else {
            ExpressionChecker::analyze($statements_checker, $stmt->class, $context);
            $lhs_type = $stmt->class->inferredType;
        }

        if (!$context->check_methods || !$lhs_type) {
            if (self::checkFunctionArguments(
                $statements_checker,
                $stmt->args,
                null,
                null,
                $context
            ) === false) {
                return false;
            }

            return null;
        }

        $has_mock = false;

        foreach ($lhs_type->getTypes() as $lhs_type_part) {
            if (!$lhs_type_part instanceof TNamedObject) {
                // this is always OK
                if ($lhs_type_part instanceof Type\Atomic\TClassString) {
                    continue;
                }

                // ok for now
                if ($lhs_type_part instanceof Type\Atomic\TLiteralClassString) {
                    continue;
                }

                if ($lhs_type_part instanceof Type\Atomic\TString) {
                    if ($config->allow_string_standin_for_class
                        && !$lhs_type_part instanceof Type\Atomic\TNumericString
                    ) {
                        continue;
                    }

                    if (IssueBuffer::accepts(
                        new InvalidStringClass(
                            'String cannot be used as a class',
                            new CodeLocation($statements_checker->getSource(), $stmt)
                        ),
                        $statements_checker->getSuppressedIssues()
                    )) {
                        // fall through
                    }

                    continue;
                }

                if ($lhs_type_part instanceof Type\Atomic\TMixed
                    || $lhs_type_part instanceof Type\Atomic\TGenericParam
                ) {
                    continue;
                }

                if ($lhs_type_part instanceof Type\Atomic\TNull
                    && $lhs_type->ignore_nullable_issues
                ) {
                    continue;
                }

                if (IssueBuffer::accepts(
                    new UndefinedClass(
                        'Type ' . $lhs_type_part . ' cannot be called as a class',
                        new CodeLocation($statements_checker->getSource(), $stmt),
                        (string) $lhs_type_part
                    ),
                    $statements_checker->getSuppressedIssues()
                )) {
                    // fall through
                }

                continue;
            }

            $fq_class_name = $lhs_type_part->value;

            $is_mock = ExpressionChecker::isMock($fq_class_name);

            $has_mock = $has_mock || $is_mock;

            if ($stmt->name instanceof PhpParser\Node\Identifier && !$is_mock) {
                $method_id = $fq_class_name . '::' . strtolower($stmt->name->name);
                $cased_method_id = $fq_class_name . '::' . $stmt->name->name;

                $args = $stmt->args;

                if (!$codebase->methods->methodExists($method_id)
                    || !MethodChecker::isMethodVisible(
                        $method_id,
                        $context->self,
                        $statements_checker->getSource()
                    )
                ) {
                    if ($codebase->methods->methodExists(
                        $fq_class_name . '::__callStatic',
                        $context->calling_method_id
                    )) {
                        $array_values = array_map(
                            /**
                             * @return PhpParser\Node\Expr\ArrayItem
                             */
                            function (PhpParser\Node\Arg $arg) {
                                return new PhpParser\Node\Expr\ArrayItem($arg->value);
                            },
                            $args
                        );

                        $args = [
                            new PhpParser\Node\Arg(new PhpParser\Node\Scalar\String_($method_id)),
                            new PhpParser\Node\Arg(new PhpParser\Node\Expr\Array_($array_values)),
                        ];

                        $method_id = $fq_class_name . '::__callstatic';
                        $cased_method_id = $fq_class_name . '::__callStatic';
                    }
                }

                $does_method_exist = MethodChecker::checkMethodExists(
                    $project_checker,
                    $cased_method_id,
                    new CodeLocation($source, $stmt),
                    $statements_checker->getSuppressedIssues(),
                    $context->calling_method_id
                );

                if (!$does_method_exist) {
                    return $does_method_exist;
                }

                $class_storage = $project_checker->classlike_storage_provider->get($fq_class_name);

                if ($class_storage->deprecated) {
                    if (IssueBuffer::accepts(
                        new DeprecatedClass(
                            $fq_class_name . ' is marked deprecated',
                            new CodeLocation($statements_checker->getSource(), $stmt)
                        ),
                        $statements_checker->getSuppressedIssues()
                    )) {
                        // fall through
                    }
                }

                if (MethodChecker::checkMethodVisibility(
                    $method_id,
                    $context->self,
                    $statements_checker->getSource(),
                    new CodeLocation($source, $stmt),
                    $statements_checker->getSuppressedIssues()
                ) === false) {
                    return false;
                }

                if ($stmt->class instanceof PhpParser\Node\Name
                    && ($stmt->class->parts[0] !== 'parent' || $statements_checker->isStatic())
                    && (
                        !$context->self
                        || $statements_checker->isStatic()
                        || !$codebase->classExtends($context->self, $fq_class_name)
                    )
                ) {
                    if (MethodChecker::checkStatic(
                        $method_id,
                        strtolower($stmt->class->parts[0]) === 'self' || $context->self === $fq_class_name,
                        !$statements_checker->isStatic(),
                        $project_checker,
                        new CodeLocation($source, $stmt),
                        $statements_checker->getSuppressedIssues(),
                        $is_dynamic_this_method
                    ) === false) {
                        // fall through
                    }

                    if ($is_dynamic_this_method) {
                        $fake_method_call_expr = new PhpParser\Node\Expr\MethodCall(
                            new PhpParser\Node\Expr\Variable(
                                'this',
                                $stmt->class->getAttributes()
                            ),
                            $stmt->name,
                            $stmt->args,
                            $stmt->getAttributes()
                        );

                        if (MethodCallChecker::analyze(
                            $statements_checker,
                            $fake_method_call_expr,
                            $context
                        ) === false) {
                            return false;
                        }

                        if (isset($fake_method_call_expr->inferredType)) {
                            $stmt->inferredType = $fake_method_call_expr->inferredType;
                        }

                        return null;
                    }
                }

                if (MethodChecker::checkMethodNotDeprecated(
                    $project_checker,
                    $method_id,
                    new CodeLocation($statements_checker->getSource(), $stmt),
                    $statements_checker->getSuppressedIssues()
                ) === false) {
                    // fall through
                }

                if (self::checkMethodArgs(
                    $method_id,
                    $args,
                    $found_generic_params,
                    $context,
                    new CodeLocation($statements_checker->getSource(), $stmt),
                    $statements_checker
                ) === false) {
                    return false;
                }

                $fq_class_name = $stmt->class instanceof PhpParser\Node\Name && $stmt->class->parts === ['parent']
                    ? (string) $statements_checker->getFQCLN()
                    : $fq_class_name;

                $self_fq_class_name = $fq_class_name;

                $return_type_candidate = $codebase->methods->getMethodReturnType(
                    $method_id,
                    $self_fq_class_name,
                    $args
                );

                if ($return_type_candidate) {
                    $return_type_candidate = clone $return_type_candidate;

                    if ($found_generic_params) {
                        $return_type_candidate->replaceTemplateTypesWithArgTypes(
                            $found_generic_params
                        );
                    }

                    $return_type_candidate = ExpressionChecker::fleshOutType(
                        $project_checker,
                        $return_type_candidate,
                        $self_fq_class_name,
                        $fq_class_name
                    );

                    $return_type_location = $codebase->methods->getMethodReturnTypeLocation(
                        $method_id,
                        $secondary_return_type_location
                    );

                    if ($secondary_return_type_location) {
                        $return_type_location = $secondary_return_type_location;
                    }

                    // only check the type locally if it's defined externally
                    if ($return_type_location && !$config->isInProjectDirs($return_type_location->file_path)) {
                        $return_type_candidate->check(
                            $statements_checker,
                            new CodeLocation($source, $stmt),
                            $statements_checker->getSuppressedIssues(),
                            $context->phantom_classes
                        );
                    }
                }

                $method_storage = $codebase->methods->getUserMethodStorage($method_id);

                if ($method_storage) {
                    if ($method_storage->assertions) {
                        self::applyAssertionsToContext(
                            $method_storage->assertions,
                            $stmt->args,
                            $method_storage->template_typeof_params ?: [],
                            $context,
                            $statements_checker
                        );
                    }

                    if ($method_storage->if_true_assertions) {
                        $stmt->ifTrueAssertions = $method_storage->if_true_assertions;
                    }

                    if ($method_storage->if_false_assertions) {
                        $stmt->ifFalseAssertions = $method_storage->if_false_assertions;
                    }
                }

                if ($config->after_method_checks) {
                    $file_manipulations = [];

                    $appearing_method_id = $codebase->methods->getAppearingMethodId($method_id);
                    $declaring_method_id = $codebase->methods->getDeclaringMethodId($method_id);

                    $code_location = new CodeLocation($source, $stmt);

                    foreach ($config->after_method_checks as $plugin_fq_class_name) {
                        $plugin_fq_class_name::afterMethodCallCheck(
                            $statements_checker,
                            $method_id,
                            $appearing_method_id,
                            $declaring_method_id,
                            null,
                            $args,
                            $code_location,
                            $context,
                            $file_manipulations,
                            $return_type_candidate
                        );
                    }

                    if ($file_manipulations) {
                        /** @psalm-suppress MixedTypeCoercion */
                        FileManipulationBuffer::add($statements_checker->getFilePath(), $file_manipulations);
                    }
                }

                if ($return_type_candidate) {
                    if (isset($stmt->inferredType)) {
                        $stmt->inferredType = Type::combineUnionTypes($stmt->inferredType, $return_type_candidate);
                    } else {
                        $stmt->inferredType = $return_type_candidate;
                    }
                }
            } else {
                if (self::checkFunctionArguments(
                    $statements_checker,
                    $stmt->args,
                    null,
                    null,
                    $context
                ) === false) {
                    return false;
                }
            }

            if ($codebase->server_mode && $method_id) {
                /** @psalm-suppress PossiblyInvalidArgument never a string, PHP Parser bug */
                $codebase->analyzer->addNodeReference(
                    $statements_checker->getFilePath(),
                    $stmt->name,
                    $method_id . '()'
                );
            }

            if ($codebase->server_mode
                && (!$context->collect_initializations
                    && !$context->collect_mutations)
                && isset($stmt->inferredType)
            ) {
                /** @psalm-suppress PossiblyInvalidArgument never a string, PHP Parser bug */
                $codebase->analyzer->addNodeType(
                    $statements_checker->getFilePath(),
                    $stmt->name,
                    (string) $stmt->inferredType
                );
            }
        }

        if ($method_id === null) {
            return self::checkMethodArgs(
                $method_id,
                $stmt->args,
                $found_generic_params,
                $context,
                new CodeLocation($statements_checker->getSource(), $stmt),
                $statements_checker
            );
        }

        if (!$config->remember_property_assignments_after_call && !$context->collect_initializations) {
            $context->removeAllObjectVars();
        }
    }
}
