<?php
namespace Swango\Cache;
interface InternelCmdInterface {
    public static function handle(string &$cmd_data): void;
}
