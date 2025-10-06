import axios from "axios";

/**
 *
 * @param {Object} api
 * @param {function} handler
 * @returns {object}
 */
export const createProxy = (api, handler) => {
    // Recursive factory function that wraps any object or function
    const makeProxyInternal = (target) => {
        // Create a callable function so the proxy can be invoked like obj()
        const fn = () => proxy;

        const proxy = new Proxy(fn, {
            // Handle property access (e.g. api.namespace.getId)
            get(_, prop) {
                const value = target[prop];
                const valueFallback = target['_' + prop];

                // Case 1: property not found, but a fallback exists (e.g. _namespace)
                if (value === undefined && typeof valueFallback === "object" && valueFallback !== null) {
                    return makeProxyInternal(valueFallback);
                }

                // Case 2: if it's a function, wrap it with the handler (for effects like loading messages)
                if (typeof value === "function") {
                    return (...args) => handler(() => value(...args));
                }

                // Case 3: if it's a nested object, recurse to make it callable too
                if (typeof value === "object" && value !== undefined) {
                    return makeProxyInternal(value);
                }

                // Case 4: return primitives or anything else as-is
                return value;
            },
            // Handle function calls (e.g. api.namespace())
            // Each call returns the same proxy so you can chain or call multiple times
            apply() {
                return proxy;
            },
        });

        return proxy;
    };

    // Initialize the root proxy
    return makeProxyInternal(api);
}

/**
 * @param {() => Promise<axios.AxiosResponse<any>>} promiseFn
 * @param {Object} message
 * @returns {Promise<axios.AxiosResponse<any>>}
 */
export function withErrorHandling(promiseFn, message) {
    return promiseFn().then(
        res => res,
        err => {
            // Handle errors
            if (err.response) {
                // The request was made and the server responded with a status code
                // that falls out of the range of 2xx
                console.error('Error response:', err.response.data); // title, detail
                console.error('Error status:', err.response.status);
                message.error(err.response.data.detail, 2.5);
            } else if (err.request) {
                // The request was made but no response was received
                console.error('Error request:', err.request);
            } else {
                // Something happened in setting up the request that triggered an Error
                console.error('Error message:', err.message);
            }
            // Rethrow the error to allow callers to handle it
            return Promise.reject(err);
        }
    );
}

/**
 *
 * @param {() => Promise<axios.AxiosResponse<any>>} promiseFn
 * @param {Object} message
 * @param {MessageData} messageData
 * @returns {Promise<axios.AxiosResponse<any>>}
 */
export function withLoadingMessage(promiseFn, message, messageData) {
    const { key, loadingContent, successContent, errorContent, duration = 2.5 } = messageData;

    message.open({ key, type: "loading", content: loadingContent, duration: 0 });

    return promiseFn().then(
        res => {
            message.open({ key, type: "success", content: successContent, duration });
            return res;
        },
        err => {
            // Handle errors
            if (err.response) {
                // The request was made and the server responded with a status code
                // that falls out of the range of 2xx
                console.error('Error response:', err.response.data); // title, detail
                console.error('Error status:', err.response.status);
                message.open({ key, type: "error", content: errorContent || err.response.data.detail, duration });
            } else if (err.request) {
                // The request was made but no response was received
                console.error('Error request:', err.request);
            } else {
                // Something happened in setting up the request that triggered an Error
                console.error('Error message:', err.message);
            }
            // Rethrow the error to allow callers to handle it
            return Promise.reject(err);
        }
    );
}