<?php

declare(strict_types=1);

/**
 * Tiny inline icon set (stroke icons, 24×24 viewBox).
 */
function hajkic_icon(string $name, string $class = ''): string
{
    $paths = [
        'search'    => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
        'filter'    => '<path d="M4 6h16M7 12h10M10 18h4"/>',
        'close'     => '<path d="m6 6 12 12M18 6 6 18"/>',
        'chevron'   => '<path d="m9 6 6 6-6 6"/>',
        'pin'       => '<path d="M12 21s7-6.1 7-11a7 7 0 1 0-14 0c0 4.9 7 11 7 11Z"/><circle cx="12" cy="10" r="2.5"/>',
        'download'  => '<path d="M12 4v11m0 0 4-4m-4 4-4-4M5 20h14"/>',
        'external'  => '<path d="M14 5h5v5M19 5l-8 8"/><path d="M19 14v5H5V5h5"/>',
        'share'     => '<circle cx="18" cy="5" r="2.5"/><circle cx="6" cy="12" r="2.5"/><circle cx="18" cy="19" r="2.5"/><path d="m8.2 10.8 7.6-4.3M8.2 13.2l7.6 4.3"/>',
        'copy'      => '<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M15 9V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v7a2 2 0 0 0 2 2h3"/>',
        'layers'    => '<path d="m12 3 9 5-9 5-9-5 9-5Z"/><path d="m3 13 9 5 9-5"/>',
        'locate'    => '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/>',
        'plus'      => '<path d="M12 5v14M5 12h14"/>',
        'minus'     => '<path d="M5 12h14"/>',
        'reset'     => '<path d="M4 11a8 8 0 1 1 2.3 6.3"/><path d="M4 4v7h7"/>',
        'menu'      => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'arrow'     => '<path d="M5 12h14m-6-6 6 6-6 6"/>',
        'gpx'       => '<path d="M6 3h8l4 4v14H6z"/><path d="M14 3v4h4"/><path d="M9 15h6"/>',
        'mountain'  => '<path d="m3 20 7-13 4 7 2-3 5 9H3Z"/>',
        'clock'     => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'route'     => '<circle cx="6" cy="6" r="2"/><circle cx="18" cy="18" r="2"/><path d="M8 6h5a3 3 0 0 1 3 3v6"/>',
        'up'        => '<path d="m6 14 6-6 6 6"/>',
        'info'      => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5"/><circle cx="12" cy="8" r=".6" fill="currentColor" stroke="none"/>',
        'check'     => '<path d="m5 12 4 4L19 6"/>',
        'location'  => '<path d="M12 21s7-6.1 7-11a7 7 0 1 0-14 0c0 4.9 7 11 7 11Z"/>',
        'flag'      => '<path d="M5 21V4h13l-2 4 2 4H5"/>',
        'camera'    => '<path d="M4 8h3l1.5-2h7L17 8h3v11H4z"/><circle cx="12" cy="13" r="3.2"/>',
    ];

    $path = $paths[$name] ?? $paths['info'];

    return '<svg class="icon ' . h($class) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $path . '</svg>';
}
