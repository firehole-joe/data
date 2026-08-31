@props([
    'striped' => true,
    'dense' => true,
    'stickyHeader' => true,
])

<div class="w-full overflow-x-auto rounded-xl border border-line bg-surface">
    <table {{ $attributes->class([
        'w-full min-w-full border-collapse text-left text-[13px] text-ink',
        $dense ? '[&_td]:py-1.5 [&_th]:py-2' : '[&_td]:py-2.5 [&_th]:py-3',
        '[&_tbody_tr:nth-child(even)]:bg-surface-2/60' => $striped,
        '[&_thead_th]:sticky [&_thead_th]:top-0 [&_thead_th]:z-10' => $stickyHeader,
    ]) }}>
        {{ $slot }}
    </table>
</div>
