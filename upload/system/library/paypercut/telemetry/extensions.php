<?php
/**
 * The store's installed extensions, for correlating a failure with a conflict.
 *
 * Codes and versions only. An extension's code is what OpenCart routes it under
 * and is public; its author and path are not needed to reproduce a conflict.
 */
class PaypercutActiveExtensions
{
    private static $registry = null;

    public static function boot($registry)
    {
        self::$registry = $registry;
    }

    /**
     * @return array code => version, sorted by code.
     */
    public static function values()
    {
        if (self::$registry === null || !self::$registry->has('db')) {
            return array();
        }

        $db = self::$registry->get('db');
        $extensions = array();

        $query = $db->query("SELECT `type`, `code` FROM `" . DB_PREFIX . "extension`");

        foreach ($query->rows as $row) {
            $code = PaypercutEvent::identifier((string)$row['type'] . '.' . (string)$row['code']);

            if ($code !== '') {
                // OpenCart does not record a version per extension; the modified
                // file list is the closest thing, and it is not per-extension
                // either. An empty version still names the conflict candidate.
                $extensions[$code] = '';
            }
        }

        // OCMod modifications carry a version, and a third-party OCMod is a
        // likelier conflict than a stock extension.
        $query = $db->query("SELECT `code`, `version` FROM `" . DB_PREFIX . "modification` WHERE status = '1'");

        foreach ($query->rows as $row) {
            $code = PaypercutEvent::identifier('ocmod.' . (string)$row['code']);

            if ($code !== '') {
                $extensions[$code] = (string)$row['version'];
            }
        }

        ksort($extensions);

        return $extensions;
    }
}
