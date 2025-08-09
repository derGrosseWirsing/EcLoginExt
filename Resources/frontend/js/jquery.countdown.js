(function ($) {
    'use strict';

    /**
     * jQuery Plugin for a simple countdown timer
     * This plugin allows you to create a countdown timer that displays hours, minutes, and seconds.
     * It updates the display every second and stops when the countdown reaches zero.
     * Usage:
     * <div class="countdown" data-hours="1" data-minutes="30" data-seconds="45">
     *     <span class="hours"></span>:<span class="minutes"></span>:<span class="seconds"></span>
     * </div>
     */
    $.plugin('swCountDown', {

        defaults: {
            hourSelector: '.hours',
            minuteSelector: '.minutes',
            secondSelector: '.seconds',
            hours: 0,
            minutes: 0,
            seconds: 0
        },
        init: function () {
            var me = this;
            me.applyDataAttributes();

            /** Check DOM-Elements */
            me.$hours = me.$el.find(me.opts.hourSelector);
            me.$minutes = me.$el.find(me.opts.minuteSelector);
            me.$seconds = me.$el.find(me.opts.secondSelector);

            /** Abort if any of the required elements are missing */
            if (me.$hours.length === 0 || me.$minutes.length === 0 || me.$seconds.length === 0) {
                return;
            }

            /** validation: always use non negative integers */
            me.opts.hours = Math.max(0, parseInt(me.opts.hours) || 0);
            me.opts.minutes = Math.max(0, parseInt(me.opts.minutes) || 0);
            me.opts.seconds = Math.max(0, parseInt(me.opts.seconds) || 0);

            me.totalSeconds = (me.opts.hours * 3600) + (me.opts.minutes * 60) + me.opts.seconds;
            me.timer = null;
            me.lastHours = null;
            me.lastMinutes = null;
            me.lastSeconds = null;

            /** Initial display update */
            me.updateDisplay();
            me.registerEvents();
            me.startCountdown();
        },

        registerEvents: function () {
            var me = this;

            /**
             * onTick fired after every interval tick
             */
            $.subscribe(me.eventNamespace + '/onTick', function() {
                me.updateDisplay();
            });
        },

        startCountdown: function() {
            var me = this;

            if (me.totalSeconds <= 0) {
                return;
            }

            me.timer = setInterval(function() {
                if (me.totalSeconds <= 0) {
                    clearInterval(me.timer);
                    me.timer = null;
                    return;
                }

                me.totalSeconds--;

                /** Fire the onTick event to update the display */
                $.publish(me.eventNamespace + '/onTick', [me]);
            }, 1000);
        },

        updateDisplay: function() {
            var me = this;

            if (!me.$hours || !me.$minutes || !me.$seconds) {
                return;
            }

            var hours = Math.floor(me.totalSeconds / 3600);
            var minutes = Math.floor((me.totalSeconds % 3600) / 60);
            var seconds = me.totalSeconds % 60;

            /** leading zeros */
            var hoursText = hours.toString().padStart(2, '0');
            var minutesText = minutes.toString().padStart(2, '0');
            var secondsText = seconds.toString().padStart(2, '0');

            /** do update only if the text has changed (to avoid unnecessary DOM updates => micro performance improvement) */
            if (me.lastHours !== hoursText) {
                me.$hours.text(hoursText);
                me.lastHours = hoursText;
            }

            if (me.lastMinutes !== minutesText) {
                me.$minutes.text(minutesText);
                me.lastMinutes = minutesText;
            }

            if (me.lastSeconds !== secondsText) {
                me.$seconds.text(secondsText);
                me.lastSeconds = secondsText;
            }
        },
        destroy: function () {
            var me = this;

            /** Stop and remove the timer */
            if (me.timer) {
                clearInterval(me.timer);
                me.timer = null;
            }

            /**  Clear the event subscription */
            if (me.eventNamespace) {
                $.unsubscribe(me.eventNamespace + '/onTick');
            }

            /** Clear DOM references */
            me.$hours = null;
            me.$minutes = null;
            me.$seconds = null;

            me._destroy();
        }
    });
})(jQuery);