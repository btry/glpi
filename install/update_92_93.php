<?php
/**
 * ---------------------------------------------------------------------
 * GLPI - Gestionnaire Libre de Parc Informatique
 * Copyright (C) 2015-2017 Teclib' and contributors.
 *
 * http://glpi-project.org
 *
 * based on GLPI - Gestionnaire Libre de Parc Informatique
 * Copyright (C) 2003-2014 by the INDEPNET Development Team.
 *
 * ---------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of GLPI.
 *
 * GLPI is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * GLPI is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with GLPI. If not, see <http://www.gnu.org/licenses/>.
 * ---------------------------------------------------------------------
 */

/** @file
* @brief
*/

/**
 * Update from 9.2 to 9.3
 *
 * @return bool for success (will die for most error)
**/
function update92to93() {
   global $DB, $migration, $CFG_GLPI;

   $current_config   = Config::getConfigurationValues('core');
   $updateresult     = true;
   $ADDTODISPLAYPREF = [];

   //TRANS: %s is the number of new version
   $migration->displayTitle(sprintf(__('Update to %s'), '9.3'));
   $migration->setVersion('9.3');

   /* ************ New Tree structure ************ */
   $tables = ['glpi_entities',
              ];

   foreach ($tables as $table) {
      // add new tree management columns
      $migration->addField($table, 'left_border', 'integer', ['after' => 'sons_cache']);
      $migration->addField($table, 'right_border', 'integer', ['after' => 'left_border']);
      $migration->addKey($table, 'left_border');
      $migration->addKey($table, 'right_border');
      // Migrate right now to update the tree tructure and benefit indexes
      $migration->migrationOneTable($table);

      $query = "SELECT COUNT(*) AS`count` FROM `$table` WHERE `left_border`='0' OR `right_border`='0'";
      $result = $DB->queryOrDie($query, "9.3 Checling if upgrade is needed for $table");
      $row = $DB->fetch_assoc($result);
      if ($row['count'] < 1) {
         // It seems the table is already updated, continue with the next one
         continue;
      }

      // initialize deep level, left and right borders
      $level = 1;
      $leftBorder = 1;
      $rightBorder = $leftBorder + 1;

      $selfForeignKey = getForeignKeyFieldForTable($table);

      // set left and right borders for items at level 1
      $query = "SELECT * FROM `$table` WHERE `level`='$level'";
      $result = $DB->queryOrDie($query);
      if ($DB->numrows($result) > 0) {
         // There is at least one entry at level 1
         while ($row = $DB->fetch_assoc($result)) {
            $id = $row['id'];
            $query = "UPDATE `$table` SET `left_border`='$leftBorder', `right_border`='$rightBorder' WHERE `id`='$id'";
            $DB->queryOrDie($query);
            $leftBorder += 2;
            $rightBorder = $leftBorder + 1;
         }
      }

      $level = 1;
      do {
         $query = "SELECT `id`, `left_border`, `right_border` FROM `$table` WHERE `level`='$level'";
         $result = $DB->query($query);
         while ($row = $DB->fetch_assoc($result)) {
            $parentId = $row['id'];
            $leftBorder = $row['right_border'];
            $rightBorder = $leftBorder + 1;

            $query = "SELECT `id` FROM `$table` WHERE `$selfForeignKey`='$parentId'";
            $result2 = $DB->query($query);
            $pushToRight = $DB->numrows($result2) * 2;
            $query = "UPDATE `$table`
                      SET `right_border`=`right_border` + '$pushToRight'
                      WHERE `right_border`>= '$leftBorder'";
            $DB->queryorDie($query);
            $query = "UPDATE `$table`
                      SET `left_border`=`left_border` + '$pushToRight'
                      WHERE `left_border`>= '$leftBorder'";
            $DB->queryorDie($query);

            while ($row2 = $DB->fetch_assoc($result2)) {
               $childId = $row2['id'];
               $query = "UPDATE `$table`
                         SET `left_border`='$leftBorder',
                         `right_border`='$rightBorder'
                         WHERE `id`='$childId'";
               $DB->queryorDie($query);
               $leftBorder += 2;
               $rightBorder = $leftBorder + 1;
            }
         }
         $level++;
      } while ($DB->numrows($result) > 0);
   }


   // ************ Keep it at the end **************
   $migration->executeMigration();

   return $updateresult;
}
