import React from "react";
import { Link } from "react-router";
import { Spin } from "antd";
import { LoadingOutlined } from "@ant-design/icons";

const antIcon = <LoadingOutlined style={{ fontSize: 13 }} spin />;

/**
 * @param {string|number} id - Target resource ID
 * @param {string} url - Target external link (e.g. MyAnimeList or AniList)
 * @param {() => Promise<any>} [fetchTitle] - Async function returning { data: { title } } or title string
 * @param {string} [initialTitle] - Initial title if already resolved
 * @param {string} [fallback] - Fallback placeholder text when id/url is empty (default: "-")
 */
export default function ExternalTitleLink({
                                              id,
                                              url,
                                              fetchTitle,
                                              initialTitle = null,
                                              fallback = "-",
                                          }) {
    const [title, setTitle] = React.useState(initialTitle);
    const [loading, setLoading] = React.useState(false);

    // Keep latest fetchTitle reference without triggering re-renders
    const fetchTitleRef = React.useRef(fetchTitle);
    fetchTitleRef.current = fetchTitle;

    React.useEffect(() => {
        if (initialTitle) {
            setTitle(initialTitle);
        }

        if (!id || !fetchTitleRef.current) {
            return;
        }

        let isMounted = true;
        setLoading(true);

        fetchTitleRef.current()
            .then(res => {
                if (!isMounted) return;

                const payload = res?.data ?? res;
                const fetchedTitle = typeof payload === "string"
                    ? payload
                    : (typeof payload?.title === "string" ? payload.title : null);

                if (fetchedTitle) {
                    setTitle(fetchedTitle);
                }
            })
            .catch(err => {
                // Log warning in dev for easier debugging
                console.warn(`[ExternalTitleLink] Failed fetching title for id: ${id}`, err);
            })
            .finally(() => {
                if (isMounted) {
                    setLoading(false);
                }
            });

        return () => {
            isMounted = false;
        };
    }, [id, initialTitle]);

    if (!id && !url) {
        return fallback;
    }

    const resolvedUrl = url || "#";
    const displayText = (title && typeof title === "string") ? title : (id ?? resolvedUrl);

    return (
        <span style={{ display: "inline-flex", alignItems: "center", gap: 6 }}>
            <Link to={resolvedUrl} target="_blank" rel="noreferrer">
                {displayText}
            </Link>
            {loading && <Spin indicator={antIcon} />}
        </span>
    );
}
