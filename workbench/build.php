<?php

foreach ([__DIR__.'/database', __DIR__.'/storage/framework/sessions'] as $directory) {
    if (! is_dir($directory)) {
        mkdir($directory, 0777, true);
    }
}
if (! file_exists(__DIR__.'/database/database.sqlite')) {
    touch(__DIR__.'/database/database.sqlite');
}
