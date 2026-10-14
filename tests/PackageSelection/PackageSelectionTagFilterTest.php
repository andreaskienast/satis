<?php

declare(strict_types=1);

/*
 * This file is part of composer/satis.
 *
 * (c) Composer <https://github.com/composer>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace Composer\Satis\PackageSelection;

use Composer\Config;
use Composer\Factory;
use Composer\IO\BufferIO;
use Composer\Util\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class PackageSelectionTagFilterTest extends TestCase
{
    private const PACKAGE_NAME = 'vendor/package';

    private string $workDir;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/satis-tag-filter-' . uniqid('', true);
        (new Filesystem())->ensureDirectoryExists($this->workDir . '/repository');

        $this->git('init', '--initial-branch=main');
        foreach (['v1.0.0', 'v2.0.0'] as $tag) {
            file_put_contents($this->workDir . '/repository/composer.json', json_encode(['name' => self::PACKAGE_NAME, 'description' => $tag], \JSON_THROW_ON_ERROR));
            $this->git('add', 'composer.json');
            $this->git('commit', '--message', $tag);
            $this->git('tag', $tag);
        }
    }

    protected function tearDown(): void
    {
        (new Filesystem())->removeDirectory($this->workDir);
    }

    /**
     * @return array<string, array{array<string, mixed>, list<string>}>
     */
    public static function dataReadTags(): array
    {
        return [
            'filter disabled' => [[], ['v1.0.0', 'v2.0.0']],
            'filter enabled' => [['filter-tags-by-require' => true], ['v2.0.0']],
            'filter enabled with require-dependencies' => [['filter-tags-by-require' => true, 'require-dependencies' => true], ['v1.0.0', 'v2.0.0']],
            'filter enabled with inline alias' => [['filter-tags-by-require' => true, 'require' => [self::PACKAGE_NAME => '2.0.0 as 3.0.0']], ['v1.0.0', 'v2.0.0']],
        ];
    }

    /**
     * @param array<string, mixed> $extraConfig
     * @param list<string> $expectedReadTags
     */
    #[DataProvider('dataReadTags')]
    public function testReadTags(array $extraConfig, array $expectedReadTags): void
    {
        $config = $extraConfig + [
            'name' => 'test/satis',
            'homepage' => 'http://localhost',
            'repositories' => [['type' => 'vcs', 'url' => $this->workDir . '/repository', 'name' => self::PACKAGE_NAME]],
            'require' => [self::PACKAGE_NAME => '^2.0'],
            'config' => ['cache-dir' => $this->workDir . '/cache'],
        ];

        unset(Config::$defaultRepositories['packagist'], Config::$defaultRepositories['packagist.org']);

        $io = new BufferIO('', OutputInterface::VERBOSITY_VERY_VERBOSE);
        $composer = (new Factory())->createComposer($io, $config, true, null, false);

        $selection = new PackageSelection(new NullOutput(), $this->workDir . '/build', $config, false, $io);
        $selected = $selection->select($composer, false);

        self::assertSame([self::PACKAGE_NAME . '-2.0.0.0'], array_keys($selected));

        $output = $io->getOutput();
        foreach (['v1.0.0', 'v2.0.0'] as $tag) {
            $message = sprintf('Reading composer.json of %s (%s)', self::PACKAGE_NAME, $tag);
            if (in_array($tag, $expectedReadTags, true)) {
                self::assertStringContainsString($message, $output);
            } else {
                self::assertStringNotContainsString($message, $output);
            }
        }
        // Branches are never skipped
        self::assertStringContainsString(sprintf('Reading composer.json of %s (main)', self::PACKAGE_NAME), $output);
    }

    private function git(string ...$arguments): void
    {
        // Identity of the test repository, independent of the local Git config
        $process = new Process(
            ['git', '-c', 'user.name=Satis', '-c', 'user.email=satis@example.com', '-c', 'commit.gpgsign=false', '-c', 'tag.gpgsign=false', ...$arguments],
            $this->workDir . '/repository'
        );
        $process->mustRun();
    }
}
