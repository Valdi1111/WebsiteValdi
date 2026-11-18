<?php

namespace App\CoreBundle\Service;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class GeetestService
{
    public const string SCRIPT_URL_V3 = 'https://static.geetest.com/static/js/gt.0.5.0.js';
    public const string SCRIPT_URL_V4 = 'https://static.geetest.com/v4/gt4.js';
    private const int SCRIPT_CACHE_TTL = 86400;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Target('core.geetest.cache')]
        private readonly CacheItemPoolInterface $geetestCache,
    ) {
    }

    /**
     * Fetches and caches the official GeeTest v3 or v4 loader script.
     */
    public function fetchScript(string $version): string
    {
        $normalizedVersion = strtolower($version) === 'v3' ? 'v3' : 'v4';
        $cacheKey = 'geetest_script_' . $normalizedVersion;

        $item = $this->geetestCache->getItem($cacheKey);
        if ($item->isHit()) {
            $cached = $item->get();
            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
        }

        $targetUrl = $normalizedVersion === 'v3' ? self::SCRIPT_URL_V3 : self::SCRIPT_URL_V4;
        $response = $this->httpClient->request(Request::METHOD_GET, $targetUrl, [
            'timeout' => 15,
        ]);

        if ($response->getStatusCode() !== Response::HTTP_OK) {
            throw new \RuntimeException(sprintf('Failed to fetch GeeTest %s script (HTTP %d).', $normalizedVersion, $response->getStatusCode()));
        }

        $content = $response->getContent();
        $item->set($content);
        $item->expiresAfter(self::SCRIPT_CACHE_TTL);
        $this->geetestCache->save($item);

        return $content;
    }

    /**
     * Renders an isolated HTML document for mounting GeeTest v3 or v4 inside an iframe.
     *
     * @param array<string, mixed> $captchaConfig
     */
    public function renderFrameHtml(array $captchaConfig, string $scriptUrl): string
    {
        $jsonConfig = json_encode($captchaConfig, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
        $escapedScriptUrl = htmlspecialchars($scriptUrl, ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="referrer" content="no-referrer" />
    <title>Security Verification</title>
    <style>
        * {
            box-sizing: border-box;
        }
        html, body {
            margin: 0;
            padding: 0;
            width: 100%;
            height: 100%;
            background: transparent;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #8c8c8c;
            font-size: 13px;
        }
        .status-container {
            text-align: center;
            padding: 16px;
        }
        .retry-btn {
            margin-top: 10px;
            padding: 6px 16px;
            border-radius: 6px;
            border: 1px solid #1677ff;
            background: #1677ff;
            color: #ffffff;
            cursor: pointer;
            font-size: 13px;
            display: none;
        }
        .retry-btn:hover {
            background: #4096ff;
        }
    </style>
    <script src="{$escapedScriptUrl}"></script>
</head>
<body>
    <div class="status-container">
        <div id="status-text">Initializing security verification...</div>
        <button id="retry-btn" class="retry-btn" type="button">Show Captcha / Retry</button>
    </div>
    <script>
        (function () {
            const mmt = {$jsonConfig};
            const statusText = document.getElementById("status-text");
            const retryBtn = document.getElementById("retry-btn");
            let activeCaptcha = null;

            function notifyParent(type, payload) {
                if (window.parent && window.parent !== window) {
                    window.parent.postMessage({ type: type, payload: payload || null }, window.location.origin);
                }
            }

            function showRetry(message) {
                statusText.textContent = message;
                retryBtn.style.display = "inline-block";
            }

            function triggerCaptcha() {
                if (!activeCaptcha) {
                    return;
                }
                retryBtn.style.display = "none";
                statusText.textContent = "Please complete the verification challenge...";
                if (typeof activeCaptcha.showCaptcha === "function") {
                    activeCaptcha.showCaptcha();
                } else if (typeof activeCaptcha.verify === "function") {
                    activeCaptcha.verify();
                }
            }

            retryBtn.addEventListener("click", function () {
                triggerCaptcha();
            });

            window.addEventListener("message", function (event) {
                if (event.origin === window.location.origin && event.data && event.data.type === "GEETEST_OPEN") {
                    triggerCaptcha();
                }
            });

            const isV4 = Number(mmt.version) === 4 || Boolean(mmt.use_v4);

            if (isV4) {
                if (typeof window.initGeetest4 !== "function") {
                    statusText.textContent = "Failed to load GeeTest v4 script.";
                    notifyParent("GEETEST_ERROR", { message: "initGeetest4 is not available" });
                    return;
                }

                const v4Config = {
                    captchaId: mmt.captcha_id || mmt.gt,
                    product: "bind",
                    language: mmt.language || "en"
                };

                if (mmt.risk_type) {
                    v4Config.riskType = mmt.risk_type;
                }
                if (mmt.session_id) {
                    v4Config.userInfo = JSON.stringify({ session_id: mmt.session_id });
                }
                if (Array.isArray(mmt.api_servers) && mmt.api_servers.length > 0) {
                    v4Config.apiServers = mmt.api_servers;
                }

                window.initGeetest4(v4Config, function (captcha) {
                    activeCaptcha = captcha;
                    captcha.onReady(function () {
                        notifyParent("GEETEST_READY");
                        triggerCaptcha();
                    });
                    captcha.onSuccess(function () {
                        const result = captcha.getValidate();
                        statusText.textContent = "Verification completed. Submitting...";
                        retryBtn.style.display = "none";
                        notifyParent("GEETEST_SUCCESS", result);
                    });
                    captcha.onError(function (err) {
                        showRetry("Verification encountered an error. Click below to retry.");
                        notifyParent("GEETEST_ERROR", err);
                    });
                    captcha.onClose(function () {
                        showRetry("Verification window closed. Click below to reopen.");
                        notifyParent("GEETEST_CLOSE");
                    });
                });
            } else {
                if (typeof window.initGeetest !== "function") {
                    statusText.textContent = "Failed to load GeeTest v3 script.";
                    notifyParent("GEETEST_ERROR", { message: "initGeetest is not available" });
                    return;
                }

                window.initGeetest({
                    gt: mmt.gt,
                    challenge: mmt.challenge,
                    new_captcha: Boolean(mmt.new_captcha ?? true),
                    offline: !(mmt.success ?? 1),
                    api_server: mmt.api_server || "api-na.geetest.com",
                    https: /^https/i.test(window.location.protocol),
                    product: "bind",
                    lang: "en"
                }, function (captcha) {
                    activeCaptcha = captcha;
                    captcha.onReady(function () {
                        notifyParent("GEETEST_READY");
                        triggerCaptcha();
                    });
                    captcha.onSuccess(function () {
                        const result = captcha.getValidate();
                        statusText.textContent = "Verification completed. Submitting...";
                        retryBtn.style.display = "none";
                        notifyParent("GEETEST_SUCCESS", result);
                    });
                    captcha.onError(function (err) {
                        showRetry("Verification encountered an error. Click below to retry.");
                        notifyParent("GEETEST_ERROR", err);
                    });
                    captcha.onClose(function () {
                        showRetry("Verification window closed. Click below to reopen.");
                        notifyParent("GEETEST_CLOSE");
                    });
                });
            }
        })();
    </script>
</body>
</html>
HTML;
    }
}
