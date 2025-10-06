import ShelvesListContent from "@BooksBundle/components/library/shelves/ShelvesListContent";
import ShelvesList from "@BooksBundle/components/library/shelves/ShelvesList";
import { useBackendApi } from "@BooksBundle/components/BackendApiContext";
import { Layout, theme as antdTheme } from "antd";
import { useParams } from "react-router-dom";
import { Helmet } from "react-helmet";
import React from "react";

export default function LibraryShelvesId() {
    const [loading, setLoading] = React.useState(true);
    const [shelves, setShelves] = React.useState([]);
    const [collapsed, setCollapsed] = React.useState(false);
    const { token: { colorBgContainer } } = antdTheme.useToken();
    const { shelfId } = useParams();
    const api = useBackendApi();

    React.useEffect(() => {
        refreshShelves();
    }, []);

    function refreshShelves() {
        setLoading(true);
        return api
            .withErrorHandling()
            .shelves()
            .get()
            .then(res => {
                setShelves(res.data);
                setLoading(false);
            });
    }

    return <>
        <Helmet>
            <title>Shelves</title>
        </Helmet>
        <Layout>
            <Layout.Sider collapsed={collapsed} collapsedWidth={0}
                          style={{ maxHeight: '100%', overflowY: 'scroll', background: colorBgContainer }}>
                <ShelvesList loading={loading} shelves={shelves} refreshShelves={refreshShelves} shelfId={shelfId}/>
            </Layout.Sider>
            <Layout.Content style={{ maxHeight: '100%', overflowY: 'scroll' }}>
                {!loading && shelfId ?
                    <ShelvesListContent collapsed={collapsed} setCollapsed={setCollapsed}
                                        shelves={shelves} setShelves={setShelves}
                                        refreshShelves={refreshShelves}/> : <></>}
            </Layout.Content>
        </Layout>
    </>;

}