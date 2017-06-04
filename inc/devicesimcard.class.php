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

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access this file directly");
}

/// Class DeviceSimcard
class DeviceSimcard extends CommonDevice {
   static protected $forward_entity_to = array('Item_DeviceSimcard', 'Infocom');

   static function getTypeName($nb=0) {
      return _n('Simcard', 'Simcards', $nb);
   }

   function getAdditionalFields() {

      return array_merge(parent::getAdditionalFields(),
                         array(array('name'  => 'phoneoperators_id',
                                     'label' => __('Phone operator'),
                                     'type'  => 'dropdownValue'),
                               array('name'  => 'devicesimcardsizes_id',
                                     'label' => __('Simcard size'),
                                     'type'  => 'dropdownValue'),
                               array('name'  => 'devicesimcardvoltages_id',
                                     'label' => __('Simcard voltage'),
                                     'type'  => 'dropdownValue'),
                               array('name'  => 'devicesimcardtypes_id',
                                     'label' => __('Simcard type'),
                                     'type'  => 'dropdownValue')
                         ));
   }

   function getSearchOptionsNew() {
      $tab = parent::getSearchOptionsNew();

      $tab[] = [
            'id'                 => '12',
            'table'              => 'glpi_phoneoperators_id',
            'field'              => 'name',
            'name'               => __('Phone operator'),
            'datatype'           => 'dropdown'
      ];

      $tab[] = [
            'id'                 => '13',
            'table'              => 'glpi_devicesimcardsizes',
            'field'              => 'name',
            'name'               => __('Simcard size'),
            'datatype'           => 'dropdown'
      ];

      $tab[] = [
            'id'                 => '14',
            'table'              => 'glpi_devicesimcardvoltages',
            'field'              => 'name',
            'name'               => __('Simcard voltage'),
            'datatype'           => 'dropdown'
      ];

      $tab[] = [
            'id'                 => '15',
            'table'              => 'glpi_devicesimcardtypes',
            'field'              => 'name',
            'name'               => __('Simcard type'),
            'datatype'           => 'dropdown'
      ];

      return $tab;
   }


}