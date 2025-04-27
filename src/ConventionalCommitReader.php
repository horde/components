<?php
namespace Horde\Components;
/**
 * See https://www.conventionalcommits.org/en/v1.0.0/
 *
 * PHP version 8
 *
 * @category Horde
 * @package  Components
 * @author   Ralf Lang <ralf.lang@ralf-lang.de>
 */
final class ConventionalCommitReader
{
    public function __construct(
        private GitCommitLog $log,
    ) {
        $this->readConventionalCommits();
    }

    private string $regex=  '/^(?P<type>build|chore|ci|docs|feat|fix|perf|refactor|revert|style|test){1}(?P<scope>\([\w\-\.]+\))?(!)?: ([\w ])+([\s\S]*)/';

    public function readConventionalCommits(): array
    {
        $conventionalCommits = [];
        
        foreach ($this->log as $commit) {
            if (preg_match($this->regex, $commit->subject, $matches)) {
                $conventionalCommits[] = $commit;
                print_r($matches);
            }
        }

        return $conventionalCommits;
    }
}