<?php

/**
 * Test class to test rcmail_action_contacts_group_rename
 *
 * @package Tests
 */
class Actions_Contacts_Group_Rename extends ActionTestCase
{
    /**
     * Class constructor
     */
    function test_class()
    {
        $object = new rcmail_action_contacts_group_rename;

        $this->assertInstanceOf('rcmail_action', $object);
    }
}