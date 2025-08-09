(function ($) {
    'use strict';

    $.plugin('swCountDown', {

        defaults: {
            hourSelector: '.hours',
            minuteSelector: '.minutes',
            secondSelector: '.seconds',
            hours: 0,
            minutes: 0,
            seconds: 0
        },

        /**
         * Initializes the plugin and register its events
         *
         * @public
         * @method init
         */
        init: function () {
            var me = this;

            me.applyDataAttributes();

            // Validierung der Eingabewerte
            me.opts.hours = Math.max(0, parseInt(me.opts.hours) || 0);
            me.opts.minutes = Math.max(0, parseInt(me.opts.minutes) || 0);
            me.opts.seconds = Math.max(0, parseInt(me.opts.seconds) || 0);

            // DOM-Elemente finden
            me.$hours = me.$el.find(me.opts.hourSelector);
            me.$minutes = me.$el.find(me.opts.minuteSelector);
            me.$seconds = me.$el.find(me.opts.secondSelector);

            // Prüfen ob DOM-Elemente existieren
            if (me.$hours.length === 0 || me.$minutes.length === 0 || me.$seconds.length === 0) {
                return;
            }

            me.totalSeconds = (me.opts.hours * 3600) + (me.opts.minutes * 60) + me.opts.seconds;
            me.timer = null;
            me.lastHours = null;
            me.lastMinutes = null;
            me.lastSeconds = null;

            // Initial display update
            me.updateDisplay();
            me.registerEvents();
            me.startCountdown();
        },

        registerEvents: function () {
            var me = this;

            // Subscribe mit eindeutigem Namespace basierend auf Element
            me.eventNamespace = 'plugin/swCountDown/' + (me.$el.attr('id') || me.$el.index());

            $.subscribe(me.eventNamespace + '/onTick', function() {
                me.updateDisplay();
            });
        },

        startCountdown: function() {
            var me = this;

            if (me.totalSeconds <= 0) {
                $.publish(me.eventNamespace + '/onComplete', [me]);
                return;
            }

            me.timer = setInterval(function() {
                if (me.totalSeconds <= 0) {
                    clearInterval(me.timer);
                    me.timer = null;
                    $.publish(me.eventNamespace + '/onComplete', [me]);
                    return;
                }

                me.totalSeconds--;
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


            var hoursText = (hours < 10 ? '0' : '') + hours;
            var minutesText = (minutes < 10 ? '0' : '') + minutes;
            var secondsText = (seconds < 10 ? '0' : '') + seconds;

            // Nur updaten wenn sich der Wert geändert hat
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

        pause: function() {
            var me = this;

            if (me.timer) {
                clearInterval(me.timer);
                me.timer = null;
            }
        },

        resume: function() {
            var me = this;

            if (!me.timer && me.totalSeconds > 0) {
                me.startCountdown();
            }
        },

        reset: function(hours, minutes, seconds) {
            var me = this;

            me.pause();

            if (typeof hours !== 'undefined') {
                me.opts.hours = Math.max(0, parseInt(hours) || 0);
            }

            if (typeof minutes !== 'undefined') {
                me.opts.minutes = Math.max(0, parseInt(minutes) || 0);
            }

            if (typeof seconds !== 'undefined') {
                me.opts.seconds = Math.max(0, parseInt(seconds) || 0);
            }

            me.totalSeconds = (me.opts.minutes * 60) + me.opts.seconds;
            me.lastHours = null;
            me.lastMinutes = null;
            me.lastSeconds = null;
            me.updateDisplay();
        },

        destroy: function () {
            var me = this;

            // Timer stoppen
            if (me.timer) {
                clearInterval(me.timer);
                me.timer = null;
            }

            // Event-Listener aufräumen
            if (me.eventNamespace) {
                $.unsubscribe(me.eventNamespace + '/onTick');
            }

            // DOM-Referenzen löschen
            me.$hours = null;
            me.$minutes = null;
            me.$seconds = null;

            me._destroy();
        }
    });
})(jQuery);