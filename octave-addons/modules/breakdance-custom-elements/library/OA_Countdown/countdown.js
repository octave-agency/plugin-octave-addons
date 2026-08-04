/*
OA COUNTDOWN
-- Drives the OA Countdown element in fixed, evergreen and recurring modes
-- Re-runnable: every call tears down existing timers before rebinding
---------------------------------------------------------- */

( function () {

    'use strict';

    var STORAGE_PREFIX = 'oaCountdown:';

    /*
    PARSE DATE
    -- Reads "YYYY-MM-DD HH:MM" without relying on Date string parsing, which
    -- differs between browsers for non-ISO input
    ---------------------------------------------------------- */

    function parseDateTime( value, timezone, offsetHours ) {

        var match = String( value || '' ).match( /(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{1,2}):(\d{2})(?::(\d{2}))?)?/ );

        if ( ! match ) {

            return null;

        }

        var year   = parseInt( match[1], 10 );
        var month  = parseInt( match[2], 10 ) - 1;
        var day    = parseInt( match[3], 10 );
        var hour   = parseInt( match[4] || '0', 10 );
        var minute = parseInt( match[5] || '0', 10 );
        var second = parseInt( match[6] || '0', 10 );

        if ( 'utc' === timezone ) {

            return Date.UTC( year, month, day, hour, minute, second );

        }

        if ( 'offset' === timezone ) {

            return Date.UTC( year, month, day, hour, minute, second ) - ( offsetHours * 3600000 );

        }

        return new Date( year, month, day, hour, minute, second ).getTime();

    }

    /*
    RECURRING TARGET
    -- Next daily or weekly occurrence of HH:MM in the visitor's local time
    ---------------------------------------------------------- */

    function recurringTarget( every, weekday, time ) {

        var parts  = String( time || '09:00' ).split( ':' );
        var hour   = parseInt( parts[0], 10 ) || 0;
        var minute = parseInt( parts[1], 10 ) || 0;
        var now    = new Date();
        var target = new Date( now.getFullYear(), now.getMonth(), now.getDate(), hour, minute, 0 );

        if ( 'week' === every ) {

            var wanted = parseInt( weekday, 10 );
            var ahead  = ( wanted - target.getDay() + 7 ) % 7;

            target.setDate( target.getDate() + ahead );

        }

        if ( target.getTime() <= now.getTime() ) {

            target.setDate( target.getDate() + ( 'week' === every ? 7 : 1 ) );

        }

        return target.getTime();

    }

    /*
    EVERGREEN TARGET
    -- Stores the deadline per visitor so the timer is personal and survives
    -- reloads; restarts once expired when the element asks for it
    ---------------------------------------------------------- */

    function evergreenTarget( key, durationMs, restart ) {

        var storageKey = STORAGE_PREFIX + key;
        var stored     = null;

        try {

            stored = window.localStorage.getItem( storageKey );

        } catch ( error ) {

            return Date.now() + durationMs;

        }

        var deadline = stored ? parseInt( stored, 10 ) : NaN;

        if ( isNaN( deadline ) || ( restart && deadline <= Date.now() ) ) {

            deadline = Date.now() + durationMs;

            try {

                window.localStorage.setItem( storageKey, String( deadline ) );

            } catch ( error ) {

                return deadline;

            }

        }

        return deadline;

    }

    /*
    STORAGE KEY
    -- Derived from the Breakdance wrapper so each placed element keeps its own
    -- evergreen deadline
    ---------------------------------------------------------- */

    function storageKey( timer, index ) {

        var host = timer.parentElement;

        if ( host && host.id ) {

            return host.id;

        }

        if ( host && host.className ) {

            return String( host.className ).replace( /\s+/g, '-' );

        }

        return 'index-' + index;

    }

    /*
    RESOLVE TARGET
    -- Picks the deadline for whichever mode the element is set to
    ---------------------------------------------------------- */

    function resolveTarget( timer, index ) {

        var data = timer.dataset;
        var mode = data.mode || 'fixed';

        if ( 'evergreen' === mode ) {

            var hours   = parseFloat( data.evergreenHours ) || 0;
            var minutes = parseFloat( data.evergreenMinutes ) || 0;
            var total   = ( hours * 3600000 ) + ( minutes * 60000 );

            if ( total <= 0 ) {

                return null;

            }

            return evergreenTarget( storageKey( timer, index ), total, '1' === data.evergreenRestart );

        }

        if ( 'recurring' === mode ) {

            return recurringTarget( data.recurEvery, data.recurWeekday, data.recurTime );

        }

        return parseDateTime( data.datetime, data.timezone, parseFloat( data.utcOffset ) || 0 );

    }

    /*
    EXPIRE
    -- Applies the configured end state once the deadline passes
    ---------------------------------------------------------- */

    function expire( timer ) {

        var action  = timer.dataset.expiry || 'message';
        var message = timer.querySelector( '[data-oa-countdown-expired]' );

        timer.classList.add( 'is-expired' );

        if ( 'hide' === action ) {

            timer.style.display = 'none';

            return;

        }

        if ( 'message' === action && message ) {

            message.hidden = false;

            return;

        }

        if ( 'redirect' === action && timer.dataset.redirect && ! timer.closest( '.breakdance-canvas' ) ) {

            window.location.href = timer.dataset.redirect;

        }

    }

    /*
    RENDER
    -- Writes the remaining time into whichever unit slots the element shows,
    -- rolling hidden units up into the largest visible one
    ---------------------------------------------------------- */

    function render( timer, remaining ) {

        var pad    = '1' === timer.dataset.pad;
        var slots  = timer.querySelectorAll( '[data-oa-countdown-value]' );
        var total  = Math.max( 0, Math.floor( remaining / 1000 ) );
        var shown  = {};

        slots.forEach( function ( slot ) {

            shown[ slot.getAttribute( 'data-oa-countdown-value' ) ] = slot;

        } );

        var days    = Math.floor( total / 86400 );
        var hours   = Math.floor( ( total % 86400 ) / 3600 );
        var minutes = Math.floor( ( total % 3600 ) / 60 );
        var seconds = total % 60;

        if ( ! shown.days ) {

            hours += days * 24;
            days = 0;

        }

        if ( ! shown.hours ) {

            minutes += hours * 60;
            hours = 0;

        }

        if ( ! shown.minutes ) {

            seconds += minutes * 60;
            minutes = 0;

        }

        var values = { days: days, hours: hours, minutes: minutes, seconds: seconds };

        Object.keys( shown ).forEach( function ( key ) {

            var value = String( values[ key ] );

            shown[ key ].textContent = pad && value.length < 2 ? '0' + value : value;

        } );

    }

    /*
    START
    -- Ticks a single element once per second until its deadline passes
    ---------------------------------------------------------- */

    function start( timer, index ) {

        var target = resolveTarget( timer, index );

        if ( null === target ) {

            render( timer, 0 );

            return;

        }

        function tick() {

            var remaining = target - Date.now();

            if ( remaining <= 0 ) {

                render( timer, 0 );
                window.clearInterval( timer.oaCountdownInterval );
                timer.oaCountdownInterval = null;
                expire( timer );

                return;

            }

            render( timer, remaining );

        }

        tick();
        timer.oaCountdownInterval = window.setInterval( tick, 1000 );

    }

    /*
    INIT
    -- Public entry point; the builder calls this again on every edit
    ---------------------------------------------------------- */

    window.oaCountdownInit = function () {

        document.querySelectorAll( '[data-oa-countdown]' ).forEach( function ( timer, index ) {

            if ( timer.oaCountdownInterval ) {

                window.clearInterval( timer.oaCountdownInterval );
                timer.oaCountdownInterval = null;

            }

            timer.classList.remove( 'is-expired' );
            timer.style.display = '';

            var message = timer.querySelector( '[data-oa-countdown-expired]' );

            if ( message ) {

                message.hidden = true;

            }

            start( timer, index );

        } );

    };

    if ( 'loading' === document.readyState ) {

        document.addEventListener( 'DOMContentLoaded', window.oaCountdownInit );

    } else {

        window.oaCountdownInit();

    }

}() );
