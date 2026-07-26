<?php

return [
    'listeners' => [
        'webman.start' => [
            support\DatabaseBootstrap::class,
        ],
    ],
];
