<?php
defined('ABSPATH') || exit;

/** Registry nguồn — thêm nguồn mới chỉ cần register(new ...). */
class VCS_Sources {
    private static $list = null;

    private static function all() {
        if (self::$list === null) {
            self::$list = [];
            self::register(new VCS_Source_Hub());       // kho tập trung VIG (vighub:hãng/slug)
            self::register(new VCS_Source_Honda());     // scrape chính hãng (data giàu hơn)
            self::register(new VCS_Source_VnExpress());
            // Nguồn tương lai: self::register(new VCS_Source_XXX());
            self::$list = apply_filters('vcs_sources', self::$list);
        }
        return self::$list;
    }

    public static function register($source) {
        if ($source instanceof VCS_Source_Interface) self::$list[$source->id()] = $source;
    }

    /** Chọn nguồn phù hợp với URL. */
    public static function detect($url) {
        foreach (self::all() as $s) if ($s->matches($url)) return $s;
        return null;
    }
}
