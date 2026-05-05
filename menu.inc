<?php

$menuEntries = Array(
    Array(
        'text' => 'Meross - Status',
        'type' => 'status',
        'page' => 'status.php',
        'wrap' => 1
    ),
    Array(
        'text' => 'Meross - Configuration',
        'type' => 'content',
        'page' => 'content.php',
        'wrap' => 1
    ),
    Array(
        'text' => 'Meross - Help',
        'type' => 'help',
        'page' => 'help/help.php',
        'wrap' => 1
    ),
    Array(
        'text' => 'Meross - About',
        'type' => 'help',
        'page' => 'about.php',
        'wrap' => 1
    )
);

foreach ($menuEntries as $entry)
    PrintMenuItem($entry);
