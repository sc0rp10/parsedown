<?php

if (!class_exists('ParsedownTest')) {
    include_once 'ParsedownTest.php';
}

class ParsedownWithReplacementsTest extends ParsedownTest
{
    /**
     * @return array
     */
    protected function initDirs()
    {
        $dirs []= dirname(__FILE__).'/data_with_replacements/';

        return $dirs;
    }

    protected function initParsedown()
    {
        $replacements = array(
            'ul' => array(
                'tag_name' => 'div',
                'class' => 'list',
            ),
            'ol' => array(
                'tag_name' => 'div',
                'class' => 'list list_ordered',
            ),
            'li' => array(
                'tag_name' => 'div',
                'class' => 'list__elem',
            ),

            'h1' => array(
                'tag_name' => 'div',
                'class' => 'title title_test title_1',
            ),
            'h2' => array(
                'tag_name' => 'div',
                'class' => 'title title_test title_2',
            ),
            'h3' => array(
                'tag_name' => 'div',
                'class' => 'title title_test title_3',
            ),
            'h4' => array(
                'tag_name' => 'div',
                'class' => 'title title_test title_4',
            ),
            'h5' => array(
                'tag_name' => 'div',
                'class' => 'title title_test title_5',
            ),
            'h6' => array(
                'tag_name' => 'div',
                'class' => 'title title_test title_6',
            ),

            'table' => array(
                'tag_name' => 'table',
                'class' => 'table_test',
            ),
            'thead' => array(
                'tag_name' => 'thead',
                'class' => 'table__thead table__thead_test',
            ),
            'tbody' => array(
                'tag_name' => 'tbody',
                'class' => 'table__tbody table__tbody_test',
            ),
            'tr' => array(
                'tag_name' => 'tr',
                'class' => 'table__tr table__tr_test',
            ),
            'th' => array(
                'tag_name' => 'th',
                'class' => 'table__th table__th_test',
            ),
            'td' => array(
                'tag_name' => 'td',
                'class' => 'table__td table__td_test',
            ),

            'p' => array(
                'tag_name' => 'div',
                'class' => 'paragraph block__paragraph',
            ),

            'blockquote' => array(
                'tag_name' => 'div',
                'class' => 'block__quote',
            ),
            'em' => array(
                'tag_name' => 'span',
                'class' => 'block__em',
            ),
            'strong' => array(
                'tag_name' => 'span',
                'class' => 'block__strong',
            ),
            'a' => array(
                'class' => 'link block__link',
                'rel' => 'nofollow',
                'target' => '_blank',
            ),

            'img' => array(
                'class' => 'block__img',
            ),
            'iframe' => array(
                'class' => 'block__iframe',
            ),
            'pre' => array(
                'class' => 'block__pre',
            ),
            'code' => array(
                'class' => 'block__code',
            ),
        );

        return new Parsedown($replacements);
    }
}
