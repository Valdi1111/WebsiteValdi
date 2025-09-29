import FileManagerContext from "@CoreBundle/components/file-manager/FileManagerContext";
import createFileManagerApi from "@CoreBundle/components/file-manager/FileManagerApi";
import FilePreview from "@CoreBundle/components/file-manager/preview/FilePreview";
import FoldersTree from "@CoreBundle/components/file-manager/folders/FoldersTree";
import FilesTable from "@CoreBundle/components/file-manager/files/FilesTable";
import FileToolbar from "@CoreBundle/components/file-manager/FileToolbar";
import { Layout, Splitter } from "antd";
import React from "react";

export default function FileManager({ apiUrl }) {
    const [info, setInfo] = React.useState(null);
    // File preview
    const [showPreview, setShowPreview] = React.useState(false);
    // Selected folder, file, clipboard
    const [selectedId, setSelectedId] = React.useState("/");
    const [selectedKey, setSelectedKey] = React.useState(null);
    const [clipboard, setClipboard] = React.useState(null);
    // Folders tree data
    const [folders, setFolders] = React.useState([{
        key: "/",
        title: "Root",
        children: [],
        isLeaf: false,
    }]);
    // Files table data
    const [files, setFiles] = React.useState([]);
    const [filesLoading, setFilesLoading] = React.useState(false);
    const api = React.useMemo(() => createFileManagerApi(apiUrl), []);

    const reloadFolders = React.useCallback(data => {
        if (data && data.key !== "/") {
            return null;
        }
        return api.fmFolders().then(
            res => {
                setFolders([{
                    key: "/",
                    title: "Root",
                    children: res.data,
                    isLeaf: !!res.data.length,
                }]);
            },
            err => console.error(err)
        );
    }, []);

    const reloadFiles = React.useCallback(() => {
        setFiles([]);
        setFilesLoading(true);
        return api.fmFiles(selectedId).then(
            res => {
                setFiles(res.data);
                setFilesLoading(false);
            },
            err => console.error(err)
        )
    }, [selectedId]);

    React.useEffect(() => {
        reloadFiles();
    }, [selectedId]);

    React.useEffect(() => {
        api.fmInfo().then(
            res => setInfo(res.data),
            err => console.error(err),
        );
    }, []);

    return <FileManagerContext value={{
        // TODO sfruttare l'info, mostrare la pienezza del disco e usare i flag per abilitare o no certe impostazioni
        info, setInfo,
        selectedId, setSelectedId,
        selectedKey, setSelectedKey,
        clipboard, setClipboard,
        folders, reloadFolders,
        files, reloadFiles, filesLoading,
        api,
    }}>
        <Layout>
            <FileToolbar showPreview={showPreview} setShowPreview={setShowPreview}/>
            <Layout.Content style={{ display: 'flex', maxHeight: '100%' }}>
                <Splitter style={{ height: '100%' }}>
                    <Splitter.Panel
                        style={{ overflow: 'auto', height: '100%' }}
                        collapsible
                        defaultSize="20%"
                        min="20%"
                        max="30%"
                    >
                        <FoldersTree/>
                    </Splitter.Panel>
                    <Splitter.Panel
                        style={{ overflow: 'auto', height: '100%' }}
                    >
                        <FilesTable/>
                    </Splitter.Panel>
                    {showPreview && <Splitter.Panel
                        style={{ overflow: 'auto', height: '100%' }}
                        defaultSize="25%"
                        min="20%"
                        max="30%"
                    >
                        <FilePreview/>
                    </Splitter.Panel>}
                </Splitter>
            </Layout.Content>
        </Layout>
    </FileManagerContext>;

}