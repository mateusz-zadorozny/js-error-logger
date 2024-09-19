// js/error-catcher.js

(function () {
    // Handle general JavaScript errors
    window.onerror = function (message, source, lineno, colno, error) {
        var data = {
            action: 'jel_log_error',
            security: jel_ajax_object.nonce,
            message: message || 'Unknown error',
            source: source || '',
            lineno: lineno || 0,
            colno: colno || 0,
            stack: error && error.stack ? error.stack : '',
            user_agent: navigator.userAgent
        };

        // Send error data to the server
        sendErrorData(data);

        // Return false to let the browser console the error as well
        return false;
    };

    // Handle unhandled promise rejections
    window.addEventListener('unhandledrejection', function (event) {
        var error = event.reason;
        var data = {
            action: 'jel_log_error',
            security: jel_ajax_object.nonce,
            message: error && error.message ? error.message : 'Unhandled promise rejection',
            source: error && error.fileName ? error.fileName : '',
            lineno: error && error.lineNumber ? error.lineNumber : 0,
            colno: error && error.columnNumber ? error.columnNumber : 0,
            stack: error && error.stack ? error.stack : '',
            user_agent: navigator.userAgent
        };

        // Send error data to the server
        sendErrorData(data);
    });

    // Handle resource loading errors
    window.addEventListener('error', function (event) {
        if (event.target && (event.target.src || event.target.href)) {
            var data = {
                action: 'jel_log_error',
                security: jel_ajax_object.nonce,
                message: 'Resource Load Error',
                source: event.target.src || event.target.href,
                lineno: 0,
                colno: 0,
                stack: '',
                user_agent: navigator.userAgent
            };

            // Send error data to the server
            sendErrorData(data);
        }
    }, true);

    function sendErrorData(data) {
        fetch(jel_ajax_object.ajax_url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: new URLSearchParams(data)
        }).catch(function (err) {
            console.error('Failed to send error data:', err);
        });
    }

})();

