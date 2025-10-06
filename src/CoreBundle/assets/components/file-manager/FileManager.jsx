import FileManagerContext from "@CoreBundle/components/file-manager/FileManagerContext";
import createFileManagerApi from "@CoreBundle/components/file-manager/FileManagerApi";
import FilePreview from "@CoreBundle/components/file-manager/preview/FilePreview";
import FoldersTree from "@CoreBundle/components/file-manager/folders/FoldersTree";
import FilesTable from "@CoreBundle/components/file-manager/files/FilesTable";
import FileManagerToolbar from "@CoreBundle/components/file-manager/FileManagerToolbar";
import { Layout, Splitter, App } from "antd";
import React from "react";

// TODO andranno modificati i vari useEffect, useMemo etc al cambio di api
export default function FileManager({ apiUrl }) {
    const [info, setInfo] = React.useState(null);
    // File preview
    const [showPreview, setShowPreview] = React.useState(false);
    // Selected folder, file, clipboard
    const [selectedFolder, setSelectedFolder] = React.useState(null);
    const [selectedFile, setSelectedFile] = React.useState(null);
    const [clipboard, setClipboard] = React.useState(null);
    // Folders tree data
    const [folders, setFolders] = React.useState([{
        id: "/",
        key: "/",
        title: "Root",
        children: [],
        isLeaf: false,
    }]);
    // Files table data
    const [files, setFiles] = React.useState([]);
    const [filesLoading, setFilesLoading] = React.useState(false);
    const app = App.useApp();

    /** @type {FileManagerAPI} */
    const api = React.useMemo(() => createFileManagerApi(apiUrl, app), [apiUrl]);

    const reloadFolders = React.useCallback(() => {
        return api
            .withErrorHandling()
            .fmFolders()
            .then(res => {
                const t = [{
                    id: "/",
                    key: "/",
                    title: "Root",
                    children: res.data,
                    isLeaf: !!res.data.length,
                }];
                setFolders(t);
                return t;
            });
    }, [api]);

    const reloadFiles = React.useCallback(() => {
        setFiles([]);
        if (!selectedFolder) {
            return Promise.resolve(null);
        }
        setFilesLoading(true);
        return api
            .withErrorHandling()
            .fmFiles(selectedFolder.id)
            .then(res => {
                setFiles(res.data);
            })
            .finally(() => setFilesLoading(false));
    }, [api, selectedFolder?.id]);

    React.useEffect(() => {
        setClipboard(null);
        reloadFolders().then(t => setSelectedFolder(t[0]));
        api
            .withErrorHandling()
            .fmInfo()
            .then(res => setInfo(res.data));
    }, [api]);

    React.useEffect(() => {
        reloadFiles().then(() => setSelectedFile(null));
    }, [selectedFolder?.id]);

    return <FileManagerContext value={{
        // TODO sfruttare l'info, mostrare la pienezza del disco e usare i flag per abilitare o no certe impostazioni
        info, setInfo,
        selectedFolder, setSelectedFolder,
        selectedFile, setSelectedFile,
        clipboard, setClipboard,
        folders, reloadFolders,
        files, reloadFiles, filesLoading,
        api,
    }}>
        <Layout>
            <FileManagerToolbar showPreview={showPreview} setShowPreview={setShowPreview}/>
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