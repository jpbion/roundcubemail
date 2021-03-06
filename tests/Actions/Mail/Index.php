<?php

/**
 * Test class to test rcmail_action_mail_index
 *
 * @package Tests
 */
class Actions_Mail_Index extends ActionTestCase
{
    /**
     * Helper method to create a HTML message part object
     */
    protected function get_html_part($body = null)
    {
        $part = new rcube_message_part;
        $part->ctype_primary   = 'text';
        $part->ctype_secondary = 'html';
        $part->body = $body ? file_get_contents(TESTS_DIR . $body) : null;
        $part->replaces = [];

        return $part;
    }

    /**
     * Class constructor
     */
    function test_class()
    {
        $object = new rcmail_action_mail_index;

        $this->assertInstanceOf('rcmail_action', $object);
    }

    /**
     * Test sanitization of a "normal" html message
     */
    function test_html()
    {
        $part = $this->get_html_part('src/htmlbody.txt');
        $part->replaces = ['ex1.jpg' => 'part_1.2.jpg', 'ex2.jpg' => 'part_1.2.jpg'];

        $params = ['container_id' => 'foo'];

        // render HTML in normal mode
        $body = rcmail_action_mail_index::print_body($part->body, $part, ['safe' => false]);
        $html = rcmail_action_mail_index::html4inline($body, $params);

        $this->assertRegExp('/src="'.$part->replaces['ex1.jpg'].'"/', $html, "Replace reference to inline image");
        $this->assertRegExp('#background="program/resources/blocked.gif"#', $html, "Replace external background image");
        $this->assertNotRegExp('/ex3.jpg/', $html, "No references to external images");
        $this->assertNotRegExp('/<meta [^>]+>/', $html, "No meta tags allowed");
        $this->assertNotRegExp('/<form [^>]+>/', $html, "No form tags allowed");
        $this->assertRegExp('/Subscription form/', $html, "Include <form> contents");
        $this->assertRegExp('/<!-- link ignored -->/', $html, "No external links allowed");
        $this->assertRegExp('/<a[^>]+ target="_blank"/', $html, "Set target to _blank");
//        $this->assertTrue($GLOBALS['REMOTE_OBJECTS'], "Remote object detected");

        // render HTML in safe mode
        $body = rcmail_action_mail_index::print_body($part->body, $part, ['safe' => true]);
        $html = rcmail_action_mail_index::html4inline($body, $params);

        $this->assertRegExp('/<style [^>]+>/', $html, "Allow styles in safe mode");
        $this->assertRegExp('#src="http://evilsite.net/mailings/ex3.jpg"#', $html, "Allow external images in HTML (safe mode)");
        $this->assertRegExp("#url\('?http://evilsite.net/newsletter/image/bg/bg-64.jpg'?\)#", $html, "Allow external images in CSS (safe mode)");
        $css = '<link rel="stylesheet" .+_action=modcss.+_u=tmp-[a-z0-9]+\.css';
        $this->assertRegExp('#'.$css.'#Ui', $html, "Filter (anonymized) external styleseehts with utils/modcss.php");
    }

    /**
     * Test the elimination of some trivial XSS vulnerabilities
     */
    function test_html_xss()
    {
        $part   = $this->get_html_part('src/htmlxss.txt');
        $washed = rcmail_action_mail_index::print_body($part->body, $part, ['safe' => true]);

        $this->assertNotRegExp('/src="skins/', $washed, "Remove local references");
        $this->assertNotRegExp('/\son[a-z]+/', $washed, "Remove on* attributes");
        $this->assertNotContains('onload', $washed, "Handle invalid style");

        $params = ['container_id' => 'foo'];
        $html   = rcmail_action_mail_index::html4inline($washed, $params);

        $this->assertNotRegExp('/onclick="return rcmail.command(\'compose\',\'xss@somehost.net\',this)"/', $html, "Clean mailto links");
        $this->assertNotRegExp('/alert/', $html, "Remove alerts");
    }

    /**
     * Test HTML sanitization to fix the CSS Expression Input Validation Vulnerability
     * reported at http://www.securityfocus.com/bid/26800/
     */
    function test_html_xss2()
    {
        $part   = $this->get_html_part('src/BID-26800.txt');
        $params = ['container_id' => 'dabody', 'safe' => true];
        $body   = rcmail_action_mail_index::print_body($part->body, $part, ['safe' => true]);
        $washed = rcmail_action_mail_index::html4inline($body, $params);

        $this->assertNotRegExp('/alert|expression|javascript|xss/', $washed, "Remove evil style blocks");
        $this->assertNotRegExp('/font-style:italic/', $washed, "Allow valid styles");
    }

    /**
     * Test the elimination of some XSS vulnerabilities
     */
    function test_html_xss3()
    {
        // #1488850
        $html = '<p><a href="data:text/html,&lt;script&gt;alert(document.cookie)&lt;/script&gt;">Firefox</a>'
            .'<a href="vbscript:alert(document.cookie)">Internet Explorer</a></p>';
        $washed = rcmail_action_mail_index::wash_html($html, ['safe' => true], []);

        $this->assertNotRegExp('/data:text/', $washed, "Remove data:text/html links");
        $this->assertNotRegExp('/vbscript:/', $washed, "Remove vbscript: links");
    }

    /**
     * Test handling of body style attributes
     */
    function test_html4inline_body_style()
    {
        $html   = '<body background="test" bgcolor="#fff" style="font-size:11px" text="#000"><p>test</p></body>';
        $params = ['container_id' => 'foo'];
        $html   = rcmail_action_mail_index::html4inline($html, $params);

        $this->assertRegExp('/<div style="font-size:11px">/', $html, "Body attributes");
        $this->assertArrayHasKey('container_attrib', $params, "'container_attrib' param set");
        $this->assertRegExp('/background-color: #fff;/', $params['container_attrib']['style'], "Body style (bgcolor)");
        $this->assertRegExp('/background-image: url\(test\)/', $params['container_attrib']['style'], "Body style (background)");
        $this->assertRegExp('/color: #000/', $params['container_attrib']['style'], "Body style (text)");
    }

    /**
     * Test washtml class on non-unicode characters (#1487813)
     * @group mbstring
     */
    function test_washtml_utf8()
    {
        $part   = $this->get_html_part('src/invalidchars.html');
        $washed = rcmail_action_mail_index::print_body($part->body, $part);

        $this->assertRegExp('/<p>(символ|симол)<\/p>/', $washed, "Remove non-unicode characters from HTML message body");
    }

    /**
     * Test inserting meta tag with required charset definition
     */
    function test_meta_insertion()
    {
        $meta = '<meta charset="'.RCUBE_CHARSET.'" />';
        $args = [
            'html_elements' => ['html', 'body', 'meta', 'head'],
            'html_attribs'  => ['charset'],
        ];

        $body   = '<html><head><meta charset="iso-8859-1_X"></head><body>Test1<br>Test2';
        $washed = rcmail_action_mail_index::wash_html($body, $args);
        $this->assertContains("<html><head>$meta</head><body>Test1", $washed, "Meta tag insertion (1)");

        $body   = '<html><head><meta http-equiv="Content-Type" content="text/html; charset=ISO-8859-1" /></head><body>Test1<br>Test2';
        $washed = rcmail_action_mail_index::wash_html($body, $args);
        $this->assertContains("<html><head>$meta</head><body>Test1", $washed, "Meta tag insertion (2)");

        $body   = 'Test1<br>Test2';
        $washed = rcmail_action_mail_index::wash_html($body, $args);
        $this->assertTrue(strpos($washed, "<html><head>$meta</head>") === 0, "Meta tag insertion (3)");

        $body   = '<html>Test1<br>Test2';
        $washed = rcmail_action_mail_index::wash_html($body, $args);
        $this->assertTrue(strpos($washed, "<html><head>$meta</head>") === 0, "Meta tag insertion (4)");

        $body   = '<html><head></head>Test1<br>Test2';
        $washed = rcmail_action_mail_index::wash_html($body, $args);
        $this->assertTrue(strpos($washed, "<html><head>$meta</head>") === 0, "Meta tag insertion (5)");

        $body   = '<html><head></head><body>Test1<br>Test2<meta charset="utf-8"></body>';
        $washed = rcmail_action_mail_index::wash_html($body, $args);
        $this->assertTrue(strpos($washed, "<html><head>$meta</head>") === 0, "Meta tag insertion (6)");
        $this->assertTrue(strpos($washed, "Test2</body>") > 0, "Meta tag insertion (7)");
    }

    /**
     * Test links pattern replacements in plaintext messages
     */
    function test_plaintext()
    {
        $part = new rcube_message_part;
        $part->ctype_primary   = 'text';
        $part->ctype_secondary = 'plain';
        $part->body = quoted_printable_decode(file_get_contents(TESTS_DIR . 'src/plainbody.txt'));
        $html = rcmail_action_mail_index::print_body($part->body, $part, ['safe' => true]);

        $this->assertRegExp('/<a href="mailto:nobody@roundcube.net" onclick="return rcmail.command\(\'compose\',\'nobody@roundcube.net\',this\)">nobody@roundcube.net<\/a>/', $html, "Mailto links with onclick");
        $this->assertRegExp('#<a rel="noreferrer" target="_blank" href="http://www.apple.com/legal/privacy">http://www.apple.com/legal/privacy</a>#', $html, "Links with target=_blank");
        $this->assertRegExp('#\\[<a rel="noreferrer" target="_blank" href="http://example.com/\\?tx\\[a\\]=5">http://example.com/\\?tx\\[a\\]=5</a>\\]#', $html, "Links with square brackets");
    }

    /**
     * Test mailto links in html messages
     */
    function test_mailto()
    {
        $part   = $this->get_html_part('src/mailto.txt');
        $params = ['container_id' => 'foo'];

        // render HTML in normal mode
        $body = rcmail_action_mail_index::print_body($part->body, $part, ['safe' => false]);
        $html = rcmail_action_mail_index::html4inline($body, $params);

        $mailto = '<a href="mailto:me@me.com"'
            .' onclick="return rcmail.command(\'compose\',\'me@me.com?subject=this is the subject&amp;body=this is the body\',this)" rel="noreferrer">e-mail</a>';

        $this->assertRegExp('|'.preg_quote($mailto, '|').'|', $html, "Extended mailto links");
    }

    /**
     * Test the elimination of HTML comments
     */
    function test_html_comments()
    {
        $part = $this->get_html_part('src/htmlcom.txt');
        $washed = rcmail_action_mail_index::print_body($part->body, $part, ['safe' => true]);

        // #1487759
        $this->assertRegExp('|<p>test1</p>|', $washed, "Buggy HTML comments");
        // but conditional comments (<!--[if ...) should be removed
        $this->assertNotRegExp('|<p>test2</p>|', $washed, "Conditional HTML comments");
    }

    /**
     * Test link attribute modifications
     */
    public function test_html_links()
    {
      // disable relative links
      $html = '<a href="/">test</a>';
      $body = rcmail_action_mail_index::print_body($html, $this->get_html_part(), ['safe' => false, 'plain' => false]);

      $this->assertNotContains('href="/"', $body);
      $this->assertContains('<a>', $body);

      $html = '<a href="https://roundcube.net">test</a>';
      $body = rcmail_action_mail_index::print_body($html, $this->get_html_part(), ['safe' => false, 'plain' => false]);

      // allow external links, add target and noreferrer
      $this->assertContains('<a href="https://roundcube.net"', $body);
      $this->assertContains(' target="_blank"', $body);
      $this->assertContains(' rel="noreferrer"', $body);
    }

    /**
     * Test potential XSS with invalid attributes
     */
    public function test_html_link_xss()
    {
      $html = '<a style="x:><img src=x onerror=alert(1)//">test</a>';
      $body = rcmail_action_mail_index::print_body($html, $this->get_html_part(), ['safe' => false, 'plain' => false]);

      $this->assertNotContains('onerror=alert(1)//">test', $body);
      $this->assertContains('<a style="x: &gt;"', $body);
    }
}
