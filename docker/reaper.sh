#!/bin/sh
# Stops `typst watch` processes (and removes their workspaces) for documents
# nobody has compiled for a while. Without this every document ever opened
# keeps a compiler process alive until the container restarts.
IDLE_MINUTES="${TYPST_IDLE_MINUTES:-20}"
while true; do
    sleep 300
    for dir in /tmp/typst_proj_*; do
        [ -d "$dir" ] || continue
        # Still in use if last_used was touched recently
        if [ -n "$(find "$dir/last_used" -mmin "-$IDLE_MINUTES" 2>/dev/null)" ]; then continue; fi
        if [ -f "$dir/watch.pid" ]; then
            pid="$(cat "$dir/watch.pid" 2>/dev/null)"
            case "$pid" in
                ''|*[!0-9]*) ;;
                *) grep -qa typst "/proc/$pid/cmdline" 2>/dev/null && kill -9 "$pid" 2>/dev/null ;;
            esac
        fi
        rm -rf "$dir"
    done
    # Leftover ZIP exports / git stderr captures from aborted requests
    find /tmp -maxdepth 1 \( -name 'typst_zip_*' -o -name 'git_err_*' \) -mmin +60 -delete 2>/dev/null
done
