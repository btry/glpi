<?php

/**
 * ---------------------------------------------------------------------
 *
 * GLPI - Gestionnaire Libre de Parc Informatique
 *
 * http://glpi-project.org
 *
 * @copyright 2015-2022 Teclib' and contributors.
 * @copyright 2003-2014 by the INDEPNET Development Team.
 * @licence   https://www.gnu.org/licenses/gpl-3.0.html
 *
 * ---------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of GLPI.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 * ---------------------------------------------------------------------
 */

namespace Glpi\Console\Database;

use Config;
use CommonDBTM;
use ITILFollowup;
use QueryExpression;
use Search;
use Ticket;
use Glpi\Console\AbstractCommand;
use Glpi\Toolbox\VersionParser;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * When migrating from GLPI 9.5 to 10.0, some HTML entities were not properly encoded.
 *
 * This CLI tool helps to fix items one by one or in small batches
 */
class FixHtmlEncodingCommand extends AbstractCommand
{
    /**
     * Error code returned when a specified itemtype does not exists
     *
     * @var integer
     */
    const ERROR_ITEMTYPE_NOT_FOUND = 1;

    /**
     * Error code returned when at least one item id is not found
     *
     * @var integer
     */
    const ERROR_ITEM_ID_NOT_FOUND = 2;

    /**
     * Error code returned when at least one field is not found
     *
     * @var integer
     */
    const ERROR_FIELD_NOT_FOUND = 3;

    /**
     * Error code returned when update of an item failed
     *
     * @var integer
     */
    const ERROR_UPDATE_FAILED = 4;

    /**
     * Error code returned when rollback file cound not be created
     *
     * @var integer
     */
    const ERROR_ROLLBACK_FILE_FAILED = 5;

    /**
     * Error code returned when rollback file cound not be created
     *
     * @var integer
     */
    const ERROR_ROLLBACK_FILE_REQUIRED = 6;

    /**
     * Items with invalid HTML
     *
     * @var array
     */
    private array $invalid_items = [];

    /**
     * Items with invalid HTML that have NOT been fixed
     *
     * @var array
     */
    private array $failed_items = [];

    /**
     * Columns which contains rich text, populated by analyzing search options
     *
     * @var array
     */
    private array $text_fields = [];

    /**
     * Ask for confirmation before updating each item ?
     *
     * @var boolean
     */
    private bool $confirm = true;

    /**
     * Base URL to compute URLs of items being changed
     *
     * @var string
     */
    private string $root_doc = '';

    protected function configure()
    {
        parent::configure();

        $this->setName('glpi:database:fix_html_encoding');
        $this->setAliases(['db:fix_html']);
        $this->setDescription(__('Fix HTML encoding in database.'));

        $this->addOption(
            'itemtype',
            null,
            InputOption::VALUE_REQUIRED,
            __('Itemtype to fix')
        );

        $this->addOption(
            'dump',
            null,
            InputOption::VALUE_OPTIONAL,
            __('Path of file containing dump of existing values.')
        );

        $this->addUsage('--itemtype=ITILFollowup [--dump=file_path.sql]');
    }

    /**
     * Check the version of the code against the version of the DB
     *
     * @return void
     */
    private function checkVersion()
    {
        $database_version = Config::getConfigurationValue('core', 'version');
        $match = version_compare(
            VersionParser::getNormalizedVersion($database_version),
            VersionParser::getNormalizedVersion(GLPI_VERSION),
            '='
        );
        if (!$match) {
            throw new \Glpi\Console\Exception\EarlyExitException(
                '<error>' . sprintf(__('GLPI files and database are not the same. Please upgrade first.')) . '</error>',
                self::ERROR_ITEMTYPE_NOT_FOUND
            );
        }
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        global $CFG_GLPI;

        $this->root_doc = Config::getConfigurationValue('core', 'url_base');
        $CFG_GLPI['root_doc'] = $this->root_doc;

        $this->checkVersion();
        $this->checkArguments();
        $this->findTextFields();
        $this->scanItems();

        $count = $this->countItems($this->invalid_items);
        if ($count < 1) {
            $output->writeln('<info>' . __('No invalid item found.') . '</info>');
            return 0;
        }

        $output->writeln('<info>' . sprintf(_n('%d invalid item found.', '%d invalid items found.', $count), $count) . '</info>');
        $this->askForConfirmation();

        if ($input->getOption('dump')) {
            $this->dumpObjects();
        }

        $this->confirm = $this->askForItemConfirmation();

        $this->fixItems();

        if ($this->countItems($this->failed_items) > 0) {
            $this->output->writeln(
                '<error>' . sprintf(__('Unable to update %s items'), count($this->failed_items)) . '</error>',
                OutputInterface::VERBOSITY_QUIET
            );
            foreach ($this->failed_items as $itemtype) {
                foreach ($this->failed_items as $item_id => $item) {
                    $this->output->writeln(
                        '<error>' . sprintf(__('Itemtype %s ID %s'), $itemtype, $item_id) . '</error>',
                        OutputInterface::VERBOSITY_QUIET
                    );
                }
            }
            return self::ERROR_UPDATE_FAILED;
        }

        $output->writeln('<info>' . __('HTML encoding has been fixed.') . '</info>');
        return 0;
    }

    /**
     * Check that the arguments are correct
     *
     * @return void
     */
    private function checkArguments()
    {
        // Check itemtype exists
        $itemtype = $this->input->getOption('itemtype');
        if (empty($itemtype) || !class_exists($itemtype) || !is_a($itemtype, CommonDBTM::class, true)) {
            throw new \Glpi\Console\Exception\EarlyExitException(
                '<error>' . sprintf(__('Itemtype %s not found'), $itemtype) . '</error>',
                self::ERROR_ITEMTYPE_NOT_FOUND
            );
        }

        // Dump mandatory if not in interactive mode
        if ($this->input->getOption('no-interaction')) {
            if (!$this->input->getOption('dump')) {
                throw new \Glpi\Console\Exception\EarlyExitException(
                    '<error>' . __('You must specify a dump file when using --no-interaction') . '</error>',
                    self::ERROR_ROLLBACK_FILE_REQUIRED
                );
            }
        }
    }

    /**
     * Dump items
     *
     * @return void
     */
    private function dumpObjects()
    {
        global $DB;

        $dump_content = '';

        foreach ($this->invalid_items as $itemtype => $items) {
            foreach ($items as $item_id => $fields) {
                // Get the item to save
                $item = new $itemtype();
                $item->getFromDB($item_id);

                // read the fields to save
                $object_state = [];
                foreach ($fields as $field) {
                    $object_state[$field] = $DB->escape($item->fields[$field]);
                }

                // Build the SQL query
                $dump_content .= $DB->buildUpdate(
                    $itemtype::getTable(),
                    $object_state,
                    ['id' => $item_id],
                ) . ';' . PHP_EOL;
            }
        }

        // Save the rollback SQL queries dump
        $dump_file_name = $this->input->getOption('dump');
        if (@file_put_contents($dump_file_name, $dump_content) == strlen($dump_content)) {
            $this->output->writeln(
                '<comment>' . sprintf(__('File %s contains SQL queries that can be used to rollback command.'), $dump_file_name) . '</comment>',
                OutputInterface::VERBOSITY_QUIET
            );
        } else {
            throw new \Glpi\Console\Exception\EarlyExitException(
                '<comment>' . sprintf(__('Failed to write rollback SQL queries in "%s" file.'), $dump_file_name) . '</comment>',
                self::ERROR_ROLLBACK_FILE_FAILED
            );
        }
    }

    private function fixItems()
    {
        foreach ($this->invalid_items as $itemtype => $items) {
            foreach ($items as $item_id => $fields) {
                $item = new $itemtype();
                if (!$item->getFromDB($item_id)) {
                    $this->failed_items[$itemtype][$item_id] = $item;
                    continue;
                }
                $url = $this->getItemUrl($item);
                $this->output->writeln(
                    '<comment>' . sprintf(__('About to fix itemtype %s ID %s - %s'), $itemtype, $item_id, $url) . '</comment>',
                    OutputInterface::VERBOSITY_QUIET
                );
                if ($this->confirm) {
                    $this->askForItemFix(false);
                }
                $this->fixOneItem($item, $fields);
            }
        }
    }

    /**
     * Find the URL to view an item
     *
     * @param CommonDBTM $item
     * @return string
     */
    private function getItemUrl(CommonDBTM $item): string
    {
        if ($item::getType() == ITILFollowup::getType()) {
            $parent_itemtype = $item->fields['itemtype'];
            $url = $parent_itemtype::getFormURLWithID($item->fields['items_id']);
        } else {
            $url = $item::getFormURLWithID($item->fields['id']);
        }

        return $url;
    }

    /**
     * Fix a single item, on specified fields
     *
     * @param CommonDBTM $item item to fix
     * @param array $fields fields names to fix
     * @return void
     */
    private function fixOneItem(CommonDBTM $item, array $fields)
    {
        global $DB;

        $itemtype = $item::getType();

        // update the item
        $update = [];
        foreach ($fields as $field) {
            $update[$field] = $this->fixOneField($item, $field);
            $update[$field] = $DB->escape($update[$field]);
        }

        $success = $DB->update(
            $itemtype::getTable(),
            $update,
            ['id' => $item->fields['id']],
        );
        if (!$success) {
            $this->failed_items[$itemtype][$item->fields['id']] = $item;
        }
    }

    /**
     * Fix a single field of an item
     *
     * @param CommonDBTM $item
     * @param string $field
     * @return string
     */
    private function fixOneField(CommonDBTM $item, string $field): string
    {
        $new_value = $item->fields[$field];

        $new_value = $this->doubleEncoding($new_value);

        if (in_array($item::getType(), [Ticket::getType(), ITILFollowup::getType()]) && $field == 'content') {
            $new_value = $this->fixEmailHeadersEncoding($new_value);
        }

        $new_value = $this->fixQuoteEntityWithoutSemicolon($new_value);

        return $new_value;
    }

    /**
     * Remove double encoding of HTML tags
     * character < is encoded &#38;lt; but should be encoded &#60;
     * character > is encoded &#38;gt; but should be encoded &#62;
     *
     * Does not take into account the content of < and > pair
     *
     * @param string $input
     * @return string
     */
    private function doubleEncoding(string $input): string
    {
        // Prepare the double encoding fix of HTML tag
        $pattern = [
            '/&#38;lt;/', // Opening tag
            '/&#38;gt;/', // closing tag
        ];
        $replace = [
            '&#60;',
            '&#62;',
        ];
        return preg_replace($pattern, $replace, $input);
    }

    /**
     * Fix double encoded HTML entities in old followups
     * @see https://github.com/glpi-project/glpi/issues/8330
     *
     * @param string $input
     * @return string
     */
    private function fixEmailHeadersEncoding(string $input): string
    {
        $output = $input;

        // Not very strict pattern for emails, but should be enough
        // Capturing parentheses:
        // 1: Triple encoded < character
        // 2: email address
        // 3: Triple encoded > character
        $pattern = '/(&#38;amp;lt;)(?<email>[^@]*?@[a-zA-Z0-9\-.]*?)(&#38;amp;gt;)/';
        $replace = '&amp;lt;${2}&amp;gt;';
        $output = preg_replace($pattern, $replace, $output);
        // Triple encoded should be now double encoded

        // Not very strict pattern for emails, but should be enough
        // Capturing parentheses:
        // 1: Double encoded < character
        // 2: email address
        // 3: Double encoded > character
        $pattern = '/(&amp;lt;)(?<email>[^@]*?@[a-zA-Z0-9\-.]*?)(&amp;gt;)/';
        $replace = '&lt;${2}&gt;';
        $output = preg_replace($pattern, $replace, $output);

        return $output;
    }

    /**
     * Fix &quot; HTML entity without its final semicolon
     * @see https://github.com/glpi-project/glpi/pull/6084
     *
     * The pattern searches for &quot (without semicolon) found only between encoded < and >
     * Therefore any ocurence found between HTML tabs are ignored
     *
     * @param string $input
     * @return string
     */
    private function fixQuoteEntityWithoutSemicolon(string $input): string
    {
        $output = $input;

        // Add the missing semicolon to &quot; HTML entity
        $pattern = '/&quot(?!;)/';
        $replace = '&quot;';
        $output = preg_replace($pattern, $replace, $output);

        return $output;
    }

    /**
     * Find rich text fields for itemtypes given as CLI argument
     *
     * @return void
     */
    protected function findTextFields()
    {
        $itemtype = $this->input->getOption('itemtype');

        $search_options = Search::getOptions($itemtype);
        foreach ($search_options as $search_option) {
            if (!isset($search_option['table'])) {
                continue;
            }
            if ($search_option['table'] == $itemtype::getTable() && ($search_option['datatype'] ?? '') == 'text') {
                $this->text_fields[$itemtype][] = $search_option['field'];
            }
        }
    }

    /**
     * Search in all items of an itemtype for bad HTML
     *
     * @return void
     */
    protected function scanItems()
    {
        $itemtype = $this->input->getOption('itemtype');
        $fields = $this->text_fields[$itemtype];

        foreach ($fields as $field) {
            $this->scanField($itemtype, $field);
        }
    }

    /**
     * Search for bad HTML in a single column of a table
     *
     * @param string $itemtype
     * @param string $field
     * @return void
     */
    protected function scanField(string $itemtype, string $field)
    {
        global $DB;

        $searches = [
            [$field => ['LIKE', '%&#38;lt;%']],
            [$field => ['LIKE', '%&#38;gt;%']],
            [$field => ['LIKE', '%&quot(?!;)/%']],
        ];

        if (in_array($itemtype, [Ticket::getType(), ITILFollowup::getType()]) && $field == 'content') {
            $searches[] = [new QueryExpression("REGEXP({$field}, '(&#38;amp;lt;)(?<email>[^@]*?@[a-zA-Z0-9\-.]*?)(&#38;amp;gt;)')")];
            $searches[] = [new QueryExpression("REGEXP({$field}, '(&amp;lt;)(?<email>[^@]*?@[a-zA-Z0-9\-.]*?)(&amp;gt;)')")];
        }

        $iterator = $DB->request([
            'SELECT' => 'id',
            'FROM'   => $itemtype::getTable(),
            'WHERE'  => [
                'OR' => $searches,
            ],
        ]);

        foreach ($iterator as $data = $iterator) {
            $this->invalid_items[$itemtype][$data['id']][] = $field;
        }
    }

    /**
     * Count items in list of invalid idems
     *
     * @return integer
     */
    protected function countItems(array $items_array): int
    {
        $count = 0;

        if (count($items_array) < 1) {
            return 0;
        }

        foreach ($items_array as $items) {
            $count += count($items);
        }

        return $count;
    }

    protected function askForItemConfirmation(bool $default_to_yes = true): bool
    {
        $confirm = false;
        if (!$this->input->getOption('no-interaction')) {
            $question_helper = $this->getHelper('question');
            $confirm = $question_helper->ask(
                $this->input,
                $this->output,
                new ConfirmationQuestion(
                    __('Do you want confirm each item?') . ($default_to_yes ? ' [Yes/no]' : ' [yes/No]'),
                    $default_to_yes
                )
            );
        } else {
            $confirm = !$default_to_yes;
        }

        return $confirm;
    }

    protected function askForItemFix(bool $default_to_yes = true): bool
    {
        $fix = false;
        if (!$this->input->getOption('no-interaction')) {
            $question_helper = $this->getHelper('question');
            $fix = $question_helper->ask(
                $this->input,
                $this->output,
                new ConfirmationQuestion(
                    __('Do you want fix this item?') . ($default_to_yes ? ' [Yes/no]' : ' [yes/No]'),
                    $default_to_yes
                )
            );
        } else {
            $fix = !$default_to_yes;
        }

        return $fix;
    }
}
