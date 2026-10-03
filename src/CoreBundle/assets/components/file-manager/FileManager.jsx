import FileManagerContext from "@CoreBundle/components/file-manager/FileManagerContext";
import createFileManagerApi from "@CoreBundle/components/file-manager/FileManagerApi";
import FilePreview from "@CoreBundle/components/file-manager/preview/FilePreview";
import FoldersTree from "@CoreBundle/components/file-manager/folders/FoldersTree";
import FilesTable from "@CoreBundle/components/file-manager/files/FilesTable";
import FileManagerToolbar from "@CoreBundle/components/file-manager/FileManagerToolbar";
import { Layout, Splitter, Drawer, Grid, App } from "antd";
import React from "react";

const { useBreakpoint } = Grid;

/**
 * Root File Manager component.
 * Manages global file system states, collapsible desktop panels, and responsive mobile drawers.
 */
export default function FileManager({ apiUrl }) {
    const [info, setInfo] = React.useState(null);
    const [showPreview, setShowPreview] = React.useState(false);
    const [selectedFolder, setSelectedFolder] = React.useState(null);
    const [selectedFile, setSelectedFile] = React.useState(null);
    const [clipboard, setClipboard] = React.useState(null);
    const [treeDrawerOpen, setTreeDrawerOpen] = React.useState(false);
    const [showTree, setShowTree] = React.useState(true); // Controls desktop directory tree collapse state

    const [folders, setFolders] = React.useState([{
        id: "/",
        key: "/",
        title: "Root",
        children: [],
        isLeaf: false,
    }]);
    const [files, setFiles] = React.useState([]);
    const [filesLoading, setFilesLoading] = React.useState(false);

    const screens = useBreakpoint();
    // Screen widths below md (768px) are treated as mobile devices
    const isMobile = !screens.md;

    const app = App.useApp();
    const api = React.useMemo(() => createFileManagerApi(apiUrl, app), [apiUrl]);

    // Fetch and synchronize root folder structure
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

    // Fetch files belonging to the active directory
    const reloadFiles = React.useCallback(() => {
        setFiles([]);
        if (!selectedFolder) return Promise.resolve(null);
        setFilesLoading(true);
        return api
            .withErrorHandling()
            .fmFiles(selectedFolder.id)
            .then(res => setFiles(res.data))
            .finally(() => setFilesLoading(false));
    }, [api, selectedFolder?.id]);

    // Initialize root directory and storage statistics on mount
    React.useEffect(() => {
        setClipboard(null);
        reloadFolders().then(t => setSelectedFolder(t[0]));
        api.withErrorHandling().fmInfo().then(res => setInfo(res.data));
    }, [api]);

    // Refresh directory content when selection changes
    React.useEffect(() => {
        reloadFiles().then(() => setSelectedFile(null));
    }, [selectedFolder?.id]);

    // Update selected folder and automatically dismiss the mobile navigation drawer
    const handleSelectFolder = (folder) => {
        setSelectedFolder(folder);
        if (isMobile) {
            setTreeDrawerOpen(false);
        }
    };

    return (
        <FileManagerContext value={{
            // TODO sfruttare l'info, mostrare la pienezza del disco e usare i flag per abilitare o no certe impostazioni
            info, setInfo,
            selectedFolder, setSelectedFolder: handleSelectFolder,
            selectedFile, setSelectedFile,
            clipboard, setClipboard,
            folders, reloadFolders,
            files, reloadFiles, filesLoading,
            treeDrawerOpen, setTreeDrawerOpen,
            showTree, setShowTree,
            showPreview, setShowPreview,
            isMobile,
            api,
        }}>
            {/* Full-height container without document-level scrollbars */}
            <Layout style={{ height: '100%', width: '100%', display: 'flex', flexDirection: 'column', overflow: 'hidden' }}>
                <div style={{ flexShrink: 0 }}>
                    <FileManagerToolbar />
                </div>

                <Layout.Content style={{ flex: 1, minHeight: 0, overflow: 'hidden', display: 'flex' }}>
                    {isMobile ? (
                        <>
                            {/* Mobile layout: Primary table view */}
                            <div style={{ flex: 1, paddingLeft: 8, paddingRight: 8, height: '100%', width: '100%', overflow: 'hidden' }}>
                                <FilesTable />
                            </div>

                            {/* Mobile directory navigation drawer */}
                            <Drawer
                                title="Folders"
                                placement="left"
                                size="100%"
                                onClose={() => setTreeDrawerOpen(false)}
                                open={treeDrawerOpen}
                                styles={{
                                    body: { paddingLeft: 8, paddingRight: 8, paddingTop: 0, paddingBottom: 8, height: '100%', overflow: 'hidden' }
                                }}
                            >
                                <FoldersTree />
                            </Drawer>

                            {/* Mobile bottom preview drawer */}
                            <Drawer
                                placement="bottom"
                                size="80%"
                                open={showPreview}
                                onClose={() => setShowPreview(false)}
                                closable={true}
                                title={null}
                                styles={{
                                    header: { display: 'none' }, // Header is delegated to FilePreview component
                                    body: { paddingLeft: 8, paddingRight: 8, paddingTop: 0, paddingBottom: 8, height: '100%', overflow: 'hidden' }
                                }}
                            >
                                <FilePreview />
                            </Drawer>
                        </>
                    ) : (
                        /* Desktop layout: Multi-panel resizable Splitter */
                        <Splitter
                            key={`splitter-${showTree ? 'tree' : 'no-tree'}-${showPreview ? 'preview' : 'no-preview'}`}
                            style={{ height: '100%', width: '100%' }}
                        >
                            {/* Folders tree panel: rendered only when showTree is true */}
                            {showTree && (
                                <Splitter.Panel
                                    style={{ height: '100%', overflow: 'hidden', paddingLeft: 8, paddingRight: 8 }}
                                    defaultSize="20%"
                                    min="200px"
                                    max="350px"
                                >
                                    <FoldersTree />
                                </Splitter.Panel>
                            )}

                            {/* Files table main pane */}
                            <Splitter.Panel
                                style={{ height: '100%', overflow: 'hidden', paddingLeft: 8, paddingRight: 8, minWidth: 0 }}
                            >
                                <FilesTable />
                            </Splitter.Panel>

                            {/* Right-hand file preview panel: rendered only when showPreview is true */}
                            {showPreview && (
                                <Splitter.Panel
                                    style={{ height: '100%', overflow: 'auto', paddingLeft: 8, paddingRight: 8 }}
                                    defaultSize="25%"
                                    min="200px"
                                    max="350px"
                                >
                                    <FilePreview />
                                </Splitter.Panel>
                            )}
                        </Splitter>
                    )}
                </Layout.Content>
            </Layout>
        </FileManagerContext>
    );
}