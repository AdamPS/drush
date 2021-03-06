<?php

namespace Drush\Drupal\Commands\core;

use Drush\Commands\DrushCommands;
use Drush\Drush;
use Drush\Log\LogLevel;
use Drush\Psysh\DrushCommand;
use Drush\Psysh\DrushHelpCommand;
use Drupal\Component\Assertion\Handle;
use Drush\Psysh\Shell;
use Psy\Configuration;
use Psy\VersionUpdater\Checker;

class CliCommands extends DrushCommands
{

    /**
     * Drush's PHP Shell.
     *
     * @command docs-repl
     * @hidden
     * @topic
     */
    public function docs()
    {
        self::printFile(DRUSH_BASE_PATH. '/docs/repl.md');
    }

    /**
     * @command core-cli
     * @description Open an interactive shell on a Drupal site.
     * @aliases php
     * @option $version-history Use command history based on Drupal version
     *   (Default is per site).
     * @topics docs-repl
     * @remote-tty
     */
    public function cli(array $options = ['version-history' => false])
    {
        $drupal_major_version = drush_drupal_major_version();
        $configuration = new Configuration();

        // Set the Drush specific history file path.
        $configuration->setHistoryFile($this->historyPath($options));

        // Disable checking for updates. Our dependencies are managed with Composer.
        $configuration->setUpdateCheck(Checker::NEVER);

        $shell = new Shell($configuration);

        if ($drupal_major_version >= 8) {
            // Register the assertion handler so exceptions are thrown instead of errors
            // being triggered. This plays nicer with PsySH.
            Handle::register();
            $shell->setScopeVariables(['container' => \Drupal::getContainer()]);

            // Add Drupal 8 specific casters to the shell configuration.
            $configuration->addCasters($this->getCasters());
        }

        // Add Drush commands to the shell.
        $shell->addCommands([new DrushHelpCommand()]);
        $shell->addCommands($this->getDrushCommands());

        // PsySH will never return control to us, but our shutdown handler will still
        // run after the user presses ^D.  Mark this command as completed to avoid a
        // spurious error message.
        drush_set_context('DRUSH_EXECUTION_COMPLETED', true);

        // Run the terminate event before the shell is run. Otherwise, if the shell
        // is forking processes (the default), any child processes will close the
        // database connection when they are killed. So when we return back to the
        // parent process after, there is no connection. This will be called after the
        // command in preflight still, but the subscriber instances are already
        // created from before. Call terminate() regardless, this is a no-op for all
        // DrupalBoot classes except DrupalBoot8.
        if ($bootstrap = Drush::bootstrap()) {
            $bootstrap->terminate();
        }

        $shell->run();
    }

    /**
     * Returns a filtered list of Drush commands used for CLI commands.
     *
     * @return array
     */
    protected function getDrushCommands()
    {
        $application = Drush::getApplication();
        $commands = $application->all();

        $ignored_commands = ['help', 'drush-psysh', 'php-eval', 'core-cli', 'php'];
        $php_keywords = $this->getPhpKeywords();

        /** @var \Consolidation\AnnotatedCommand\AnnotatedCommand $command */
        foreach ($commands as $name => $command) {
            $definition = $command->getDefinition();

            // Ignore some commands that don't make sense inside PsySH, are PHP keywords
            // are hidden, or are aliases.
            if (in_array($name, $ignored_commands) || in_array($name, $php_keywords) || ($name !== $command->getName())) {
                unset($commands[$name]);
            } else {
                $aliases = $command->getAliases();
                // Make sure the command aliases don't contain any PHP keywords.
                if (!empty($aliases)) {
                    $command->setAliases(array_diff($aliases, $php_keywords));
                }
            }
        }

        return array_map(function ($command) {
            return new DrushCommand($command);
        }, $commands);
    }

    /**
     * Returns a mapped array of casters for use in the shell.
     *
     * These are Symfony VarDumper casters.
     * See http://symfony.com/doc/current/components/var_dumper/advanced.html#casters
     * for more information.
     *
     * @return array.
     *   An array of caster callbacks keyed by class or interface.
     */
    protected function getCasters()
    {
        return [
        'Drupal\Core\Entity\ContentEntityInterface' => 'Drush\Psysh\Caster::castContentEntity',
        'Drupal\Core\Field\FieldItemListInterface' => 'Drush\Psysh\Caster::castFieldItemList',
        'Drupal\Core\Field\FieldItemInterface' => 'Drush\Psysh\Caster::castFieldItem',
        'Drupal\Core\Config\Entity\ConfigEntityInterface' => 'Drush\Psysh\Caster::castConfigEntity',
        'Drupal\Core\Config\ConfigBase' => 'Drush\Psysh\Caster::castConfig',
        'Drupal\Component\DependencyInjection\Container' => 'Drush\Psysh\Caster::castContainer',
        'Drupal\Component\Render\MarkupInterface' => 'Drush\Psysh\Caster::castMarkup',
        ];
    }

    /**
     * Returns the file path for the CLI history.
     *
     * This can either be site specific (default) or Drupal version specific.
     *
     * @param array $options
     *
     * @return string.
     */
    protected function historyPath(array $options)
    {
        $cli_directory = drush_directory_cache('cli');
        $drupal_major_version = drush_drupal_major_version();

        // If there is no drupal version (and thus no root). Just use the current
        // path.
        // @todo Could use a global file within drush?
        if (!$drupal_major_version) {
            $file_name = 'global-' . md5(drush_cwd());
        } // If only the Drupal version is being used for the history.
        else if ($options['version-history']) {
            $file_name = "drupal-$drupal_major_version";
        } // If there is an alias, use that in the site specific name. Otherwise,
        // use a hash of the root path.
        else {
            if ($alias = drush_get_context('DRUSH_TARGET_SITE_ALIAS')) {
                $site = drush_sitealias_get_record($alias);
                $site_suffix = $site['#name'];
            } else {
                $drupal_root = drush_get_context('DRUSH_DRUPAL_ROOT');
                $site_suffix = md5($drupal_root);
            }

            $file_name = "drupal-site-$site_suffix";
        }

        $full_path = "$cli_directory/$file_name";

        // Output the history path if verbose is enabled.
        if (drush_get_context('DRUSH_VERBOSE')) {
            $this->logger()->log(LogLevel::SUCCESS, dt('History: @full_path', ['@full_path' => $full_path]));
        }

        return $full_path;
    }

    /**
     * Returns a list of PHP keywords.
     *
     * This will act as a blacklist for command and alias names.
     *
     * @return array
     */
    protected function getPhpKeywords()
    {
        return [
        '__halt_compiler',
        'abstract',
        'and',
        'array',
        'as',
        'break',
        'callable',
        'case',
        'catch',
        'class',
        'clone',
        'const',
        'continue',
        'declare',
        'default',
        'die',
        'do',
        'echo',
        'else',
        'elseif',
        'empty',
        'enddeclare',
        'endfor',
        'endforeach',
        'endif',
        'endswitch',
        'endwhile',
        'eval',
        'exit',
        'extends',
        'final',
        'for',
        'foreach',
        'function',
        'global',
        'goto',
        'if',
        'implements',
        'include',
        'include_once',
        'instanceof',
        'insteadof',
        'interface',
        'isset',
        'list',
        'namespace',
        'new',
        'or',
        'print',
        'private',
        'protected',
        'public',
        'require',
        'require_once',
        'return',
        'static',
        'switch',
        'throw',
        'trait',
        'try',
        'unset',
        'use',
        'var',
        'while',
        'xor',
        ];
    }
}
