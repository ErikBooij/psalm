<?php
namespace Psalm\Provider;

use PhpParser;
use Psalm\Checker\ProjectChecker;

class FileProvider
{
    /**
     * @var array<string, string>
     */
    private $temp_files = [];

    /**
     * @param  string  $file_path
     *
     * @return string
     */
    public function getContents($file_path)
    {
        if (isset($this->temp_files[strtolower($file_path)])) {
            return $this->temp_files[strtolower($file_path)];
        }

        return (string)file_get_contents($file_path);
    }

    /**
     * @param  string  $file_path
     * @param  string  $file_contents
     *
     * @return void
     */
    public function setContents($file_path, $file_contents)
    {
        file_put_contents($file_path, $file_contents);
    }

    /**
     * @param  string $file_path
     *
     * @return int
     */
    public function getModifiedTime($file_path)
    {
        return (int)filemtime($file_path);
    }

    /**
     * @param \LanguageServer\Protocol\TextDocumentContentChangeEvent[] $changes
     */
    public function addTemporaryFileChanges(string $file_path, array $changes)
    {
        $this->temp_files[strtolower($file_path)] = $changes[0]->text;
    }

    public function removeTemporaryFileChanges(string $file_path)
    {
        unset($this->temp_files[strtolower($file_path)]);
    }

    /**
     * @param  string $file_path
     *
     * @return bool
     */
    public function fileExists($file_path)
    {
        return file_exists($file_path);
    }
}
