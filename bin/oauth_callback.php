<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>LinkedIn OAuth Callback</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body>
<script>
    (function () {
        function getParam(name) {
            try {
                return new URLSearchParams(window.location.search).get(name);
            } catch (e) {
                return null;
            }
        }

        const code = getParam('code');
        const state = getParam('state');
        const error = getParam('error') || getParam('error_description');

        const payload = {
            provider: 'linkedin',
            code: code,
            state: state,
            error: error || null
        };

        if (window.opener && window.opener !== window) {
            window.opener.postMessage(payload, window.location.origin);
        }

        window.close();
    })();
</script>
</body>
</html>
