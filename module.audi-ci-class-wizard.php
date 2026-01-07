<?php
/**
 * Module definition for audi-ci-class-wizard
 */

SetupWebPage::AddModule(
    __FILE__,
    'audi-ci-class-wizard/1.0.0',
    array(
        'label' => 'Audi CI Class Wizard',
        'category' => 'business',

        'dependencies' => array(
            'itop-config-mgmt/3.0.0',
        ),

        'mandatory' => false,
        'visible' => true,

        'datamodel' => array(
            'datamodel.audi-ci-class-wizard.xml',
        ),

        'webservice' => array(),
        'data.struct' => array(),
        'data.sample' => array(),

        'doc.manual_setup' => '',
        'doc.more_information' => '',
        'settings' => array(),
    )
);

