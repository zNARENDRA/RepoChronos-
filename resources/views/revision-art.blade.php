<figure class="revision-art" aria-label="Illustration of stacked file revisions">
    <div class="art-grid"></div>
    <svg viewBox="0 0 400 300" aria-hidden="true" focusable="false">
        @foreach([3,2,1,0] as $layer)
            <g transform="translate(0 {{ 22*$layer }})">
                <g class="revision-layer layer-{{ $layer }}">
                    <path class="sheet" d="M46 115 191 37 Q199 33 207 37 L353 114 Q360 118 352 123 L208 204 Q200 208 192 204 L47 124 Q39 120 46 115Z"/>
                    @if($layer===0)
                        <path class="track" d="m109 127 63-34 61 33 51-28"/>
                        <path class="track track-signal" d="m109 127 63-34 61 33 51-28"/>
                        <path class="branch" d="m172 93 31-18 36 19"/>
                        @foreach([[109,127],[172,93],[233,126],[284,98],[239,94]] as [$x,$y])
                            <ellipse class="node" cx="{{ $x }}" cy="{{ $y }}" rx="5" ry="3"/>
                        @endforeach
                    @endif
                </g>
            </g>
        @endforeach
    </svg>
    <figcaption><span class="signal-dot"></span>Revision history</figcaption>
</figure>
