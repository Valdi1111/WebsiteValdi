import ShelvesContent from "@BooksBundle/components/library/shelves/ShelvesContent";
import ShelvesList from "@BooksBundle/components/library/shelves/ShelvesList";
import ShelvesContext from "@BooksBundle/components/library/shelves/ShelvesContext";
import { useBackendApi } from "@BooksBundle/components/BackendApiContext";
import { Layout, theme as antdTheme } from "antd";
import { useParams } from "react-router";
import React from "react";

export default function LibraryShelvesId() {
    const [shelvesLoading, setShelvesLoading] = React.useState(true);
    const [shelves, setShelves] = React.useState([]);

    const [contentLoading, setContentLoading] = React.useState(false);
    const [content, setContent] = React.useState([]);

    const [selectedShelf, setSelectedShelf] = React.useState(null);
    const [collapsed, setCollapsed] = React.useState(false);

    const { token: { colorBgContainer } } = antdTheme.useToken();
    const api = useBackendApi();
    const { shelfId } = useParams();

    const refreshShelves = React.useCallback(() => {
        setShelvesLoading(true);
        return api
            .withErrorHandling()
            .shelves()
            .get()
            .then(res => {
                setShelves(res.data || []);
            })
            .finally(() => {
                setShelvesLoading(false);
            });
    }, [api]);

    // Accepts an optional explicit ID, otherwise falls back to route shelfId or selectedShelf
    const refreshContent = React.useCallback((idOverride) => {
        const targetId = idOverride ?? shelfId ?? selectedShelf?.id;

        if (!targetId) {
            setContent([]);
            setContentLoading(false);
            return Promise.resolve();
        }

        setContentLoading(true);
        return api
            .withErrorHandling()
            .shelves()
            .getBooks(targetId)
            .then(res => {
                // Safeguard against missing nested properties
                setContent(res.data?.sub_shelves ?? res.data ?? []);
            })
            .catch(() => {
                setContent([]);
            })
            .finally(() => {
                setContentLoading(false);
            });
    }, [api, shelfId, selectedShelf?.id]);

    // Initial fetch for the shelves list on mount
    React.useEffect(() => {
        refreshShelves();
    }, [refreshShelves]);

    // Sync the selected shelf state with the route parameter and fetched shelves
    React.useEffect(() => {
        if (!shelfId) {
            setSelectedShelf(null);
            setContent([]);
            return;
        }
        const current = shelves.find(s => String(s.id) === String(shelfId));
        setSelectedShelf(current || null);
    }, [shelves, shelfId]);

    // Fetch shelf contents whenever the shelf ID in the route changes
    React.useEffect(() => {
        if (shelfId) {
            refreshContent(shelfId);
        } else {
            setContent([]);
        }
    }, [shelfId, refreshContent]);

    return (
        <ShelvesContext.Provider value={{
            shelvesLoading, refreshShelves, shelves,
            contentLoading, refreshContent, setContent, content,
            selectedShelf, setSelectedShelf,
            collapsed, setCollapsed,
        }}>
            <title>Shelves</title>
            <Layout>
                <Layout.Sider collapsed={collapsed} collapsedWidth={0}
                              style={{ maxHeight: '100%', overflowY: 'scroll', background: colorBgContainer }}>
                    <ShelvesList/>
                </Layout.Sider>
                <Layout.Content style={{ maxHeight: '100%', overflowY: 'scroll' }}>
                    <ShelvesContent/>
                </Layout.Content>
            </Layout>
        </ShelvesContext.Provider>
    );
}
