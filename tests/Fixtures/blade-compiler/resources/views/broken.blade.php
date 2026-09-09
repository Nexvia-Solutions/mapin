{{-- Compiles fine at the Blade level (@php/@endphp just wraps its content in <?php ?> verbatim),
     but the PHP inside is invalid - exercises the "compiled output failed to parse" branch, not a
     Blade-compile-level failure, which BladeCompiler itself rarely produces for well-formed
     directive syntax. --}}
@php
    $x = ;
@endphp
