import { useCallback, useRef, useState } from "react";

/**
 * Shared state machine of the direct-login wizard: credentials -> captcha -> (provider steps) -> authenticated.
 *
 * - `captcha_required` moves to the "captcha" step, `authenticated` resets the wizard and calls onLoginSuccess.
 * - Any other status is passed to onStatus(data, { setStep }) so providers can add their own steps
 *   (e.g. HoYoverse email verification).
 * - onReset lets the provider clear its own forms and timers when the wizard is reset.
 *
 * @param {{ api: Object, reloginAccount: ?Object, onLoginSuccess?: Function, onStatus?: Function, onReset?: Function }} options
 */
export default function useLoginWizard({ api, reloginAccount = null, onLoginSuccess, onStatus, onReset }) {
    const [step, setStep] = useState("credentials");
    const [loginSessionId, setLoginSessionId] = useState(null);
    const [loading, setLoading] = useState(false);

    // Always call the latest callbacks without re-creating the handlers below
    const callbacksRef = useRef({});
    callbacksRef.current = { onLoginSuccess, onStatus, onReset };

    const reset = useCallback(() => {
        setStep("credentials");
        setLoginSessionId(null);
        setLoading(false);
        callbacksRef.current.onReset?.();
    }, []);

    const handleStepResult = useCallback((data) => {
        if (!data) {
            return;
        }
        const sessionId = data.login_session_id ?? data.loginSessionId;
        if (sessionId) {
            setLoginSessionId(sessionId);
        }

        if (data.status === "captcha_required") {
            setStep("captcha");
        } else if (data.status === "authenticated") {
            reset();
            callbacksRef.current.onLoginSuccess?.(data.account);
        } else {
            callbacksRef.current.onStatus?.(data, { setStep });
        }
    }, [reset]);

    /**
     * Runs a login API call: handles the loading flag and forwards the step result.
     * Errors are already displayed by the API error handling.
     */
    const run = useCallback((promise) => {
        setLoading(true);
        return promise
            .then((res) => handleStepResult(res.data))
            .catch(() => {})
            .finally(() => setLoading(false));
    }, [handleStepResult]);

    const start = useCallback((payload) => run(
        reloginAccount
            ? api.withErrorHandling().accounts().reloginStart(reloginAccount.id, payload)
            : api.withErrorHandling().accounts().loginStart(payload)
    ), [api, reloginAccount, run]);

    const submitCaptcha = useCallback((captchaPayload) => {
        if (!loginSessionId) {
            return;
        }
        run(api.withErrorHandling().accounts().loginCaptcha({
            login_session_id: loginSessionId,
            captcha: captchaPayload,
        }));
    }, [api, loginSessionId, run]);

    return { step, setStep, loginSessionId, loading, run, start, submitCaptcha, reset };
}
