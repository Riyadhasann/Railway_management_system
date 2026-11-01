@once
<style>
    .sg-wrap { display: flex; flex-wrap: wrap; gap: 1.5rem; align-items: flex-start; }
    .sg-coach { border: 1px solid rgb(0 0 0 / .1); border-radius: .75rem; padding: .75rem; background: #fff; }
    .dark .sg-coach { border-color: rgb(255 255 255 / .1); background: rgb(255 255 255 / .03); }
    .sg-coach-head { display: flex; justify-content: space-between; gap: 1rem; margin-bottom: .5rem; font-size: .875rem; }
    .sg-coach-head strong { font-weight: 600; }
    .sg-muted { color: rgb(107 114 128); font-size: .75rem; }
    .sg-grid { display: grid; gap: .3rem; }
    .sg-cell { width: 2.6rem; height: 2.3rem; border-radius: .4rem; font-size: .7rem; line-height: 1.05;
               display: flex; flex-direction: column; align-items: center; justify-content: center;
               border: 1px solid transparent; position: relative; user-select: none; }
    button.sg-cell { cursor: pointer; }
    button.sg-cell:focus-visible { outline: 2px solid rgb(59 130 246); outline-offset: 1px; }
    .sg-empty { border: 1px dashed rgb(0 0 0 / .12); background: transparent; color: transparent; }
    .dark .sg-empty { border-color: rgb(255 255 255 / .12); }
    .sg-aisle { background: transparent; border: 0; }
    .sg-seat { background: rgb(236 253 245); border-color: rgb(16 185 129 / .45); color: rgb(6 95 70); }
    .dark .sg-seat { background: rgb(16 185 129 / .15); color: rgb(167 243 208); }
    .sg-held { background: rgb(254 243 199); border-color: rgb(245 158 11 / .55); color: rgb(146 64 14); }
    .dark .sg-held { background: rgb(245 158 11 / .18); color: rgb(253 230 138); }
    .sg-booked { background: rgb(229 231 235); border-color: rgb(156 163 175 / .6); color: rgb(75 85 99); }
    .dark .sg-booked { background: rgb(255 255 255 / .08); color: rgb(156 163 175); }
    .sg-blocked { background: rgb(254 226 226); border-color: rgb(239 68 68 / .55); color: rgb(153 27 27);
                  background-image: repeating-linear-gradient(45deg, transparent 0 4px, rgb(239 68 68 / .12) 4px 8px); }
    .dark .sg-blocked { background-color: rgb(239 68 68 / .15); color: rgb(254 202 202); }
    .sg-selected { outline: 3px solid rgb(59 130 246); outline-offset: 1px; }
    .sg-berth { font-size: .6rem; opacity: .75; }
    .sg-flag { position: absolute; top: 1px; right: 3px; font-size: .6rem; font-weight: 700; }
    .sg-legend { display: flex; flex-wrap: wrap; gap: .9rem; font-size: .75rem; align-items: center; }
    .sg-legend .sg-cell { width: 1.2rem; height: 1.2rem; display: inline-flex; vertical-align: middle; margin-right: .3rem; }
    .sg-tools { display: flex; flex-wrap: wrap; gap: .4rem; }
    .sg-tool { padding: .35rem .7rem; border-radius: .5rem; font-size: .8rem; border: 1px solid rgb(0 0 0 / .15); cursor: pointer; background: transparent; }
    .dark .sg-tool { border-color: rgb(255 255 255 / .2); }
    .sg-tool[aria-pressed="true"] { background: rgb(16 185 129); border-color: rgb(16 185 129); color: #fff; }
</style>
@endonce
