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

include ('../inc/includes.php');

Simcard::canUpdate();

$simcard_item = new Simcard_Item();
if (isset($_POST["additem"])) {
   $simcard_item->can(-1, CREATE, $_POST);
   if ($newID = $simcard_item->add($_POST)) {
   }
} else if (isset($_POST["delete_items"])) {
   if (isset($_POST['todelete'])) {
      foreach ($_POST['todelete'] as $id => $val) {
         if ($val == 'on') {
            $simcard_item->can($id, DELETE, $_POST);
            $ok = $simcard_item->delete(array('id' => $id));
         }
      }
   }
}
Html::back();
