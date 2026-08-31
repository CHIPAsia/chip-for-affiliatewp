<?php
$GLOBALS['__dbg'] = true;
// Intercept: wrap absint_impl to log backtrace when given an object.
function absint_impl_dbg($v) {
    if (is_object($v)) {
        echo "absint called with OBJECT: ", get_class($v), "\n";
        debug_print_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 4);
        exit(9);
    }
    return abs((int) $v);
}
