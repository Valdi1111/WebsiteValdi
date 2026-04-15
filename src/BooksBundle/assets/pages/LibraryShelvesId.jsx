import ShelvesContent from "@BooksBundle/components/library/shelves/ShelvesContent";
import ShelvesList from "@BooksBundle/components/library/shelves/ShelvesList";
import ShelvesContext from "@BooksBundle/components/library/shelves/ShelvesContext";
import { useBackendApi } from "@BooksBundle/components/BackendApiContext";
import { Layout, theme as antdTheme } from "antd";
import { useParams } from "react-router-dom";
import { Helmet } from "react-helmet";
import React from "react";

export default function LibraryShelvesId() {
    const [shelvesLoading, setShelvesLoading] = React.useState(true);
    const [shelves, setShelves] = React.useState([]);

    const [contentLoading, setContentLoading] = React.useState(true);
    const [content, setContent] = React.useState([]);

    const [selectedShelf, setSelectedShelf] = React.useState(null);
    const [collapsed, setCollapsed] = React.useState(false);

    const { token: { colorBgContainer } } = antdTheme.useToken();
    const api = useBackendApi();
    const { shelfId } = useParams();

    const refreshShelves = React.useCallback(() => {
        setShelves([]);
        setShelvesLoading(true);
        return api
            .withErrorHandling()
            .shelves()
            .get()
            .then(res => {
                setShelves(res.data);
                setShelvesLoading(false);
            });
    }, []);

    const refreshContent = React.useCallback(() => {
        if (!selectedShelf) {
            setContent([]);
            return;
        }
        setContent([]);
        setContentLoading(true);
        return api
            .withErrorHandling()
            .shelves()
            .getBooks(selectedShelf.id)
            .then(res => {
                setContent(res.data.sub_shelves);
                setContentLoading(false);
            });
    }, [selectedShelf?.id]);

    React.useEffect(() => {
        refreshShelves();
    }, []);

    React.useEffect(() => {
        refreshContent();
    }, [selectedShelf?.id]);

    React.useEffect(() => {
        if (!shelfId) {
            setSelectedShelf(null);
            return;
        }
        setSelectedShelf(shelves.find(s => s.id == shelfId));
    }, [shelves, shelfId]);

    return <ShelvesContext value={{
        shelvesLoading, refreshShelves, shelves,
        contentLoading, setContent, content,
        selectedShelf, setSelectedShelf,
        collapsed, setCollapsed,
    }}>
        <Helmet>
            <title>Shelves</title>
        </Helmet>
        <Layout>
            <Layout.Sider collapsed={collapsed} collapsedWidth={0}
                          style={{ maxHeight: '100%', overflowY: 'scroll', background: colorBgContainer }}>
                <ShelvesList/>
            </Layout.Sider>
            <Layout.Content style={{ maxHeight: '100%', overflowY: 'scroll' }}>
                <ShelvesContent/>
            </Layout.Content>
        </Layout>
    </ShelvesContext>;

}