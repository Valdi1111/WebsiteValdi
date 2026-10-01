import { useFileManager } from "@CoreBundle/components/file-manager/FileManagerContext";
import { App, Dropdown, Form, Input, Modal } from "antd";
import {
    CopyOutlined,
    DeleteOutlined,
    DownloadOutlined,
    EditOutlined,
    ExclamationCircleFilled,
    EyeOutlined,
    FolderOpenOutlined,
    LinkOutlined,
    ScissorOutlined,
    SnippetsOutlined
} from "@ant-design/icons";
import React from "react";

/**
 * Context menu and action dropdown wrapper for file manager table rows.
 * Supports both right-click context menus on desktop and tap-triggered popups on mobile.
 */
export default function FilesTableRowDropdown({ children, row, trigger = ['contextMenu'] }) {
    const [visibleRename, setVisibleRename] = React.useState(false);
    const [confirmLoadingRename, setConfirmLoadingRename] = React.useState(false);
    const [formRename] = Form.useForm();
    const { modal } = App.useApp();

    const {
        api,
        selectedFolder,
        setSelectedFolder,
        setSelectedFile,
        reloadFolders,
        reloadFiles,
        clipboard,
        setClipboard,
        setShowPreview
    } = useFileManager();

    // Directly opens a file in a new tab or enters into the folder
    const openItem = React.useCallback(() => {
        if (!row) return;
        if (row.type === 'folder') {
            setSelectedFolder(row);
            return;
        }
        const link = document.createElement('a');
        link.href = api.fmDirectUrl(row.id);
        link.target = '_blank';
        link.click();
        link.remove();
    }, [row, api, setSelectedFolder]);

    // Build context menu options dynamically based on item type (file vs folder)
    const items = React.useMemo(() => {
        const pasteItem = {
            key: 'paste',
            label: 'Paste',
            icon: <SnippetsOutlined/>,
            extra: 'Ctrl+V',
            onClick: () => onPaste(),
            disabled: !clipboard,
        };
        if (!row) return [pasteItem];

        const out = [];

        // 1. Primary navigation action (Navigate into folder or open direct file URL)
        out.push({
            key: 'open',
            label: row.type === 'folder' ? 'Open folder' : 'Open file',
            icon: row.type === 'folder' ? <FolderOpenOutlined /> : <LinkOutlined />,
            onClick: openItem,
        });

        // 2. File-specific actions (Preview, Download, Clipboard)
        if (row.type !== 'folder') {
            out.push({
                key: 'preview',
                label: 'Preview',
                icon: <EyeOutlined />,
                onClick: () => {
                    setSelectedFile(row);
                    setShowPreview?.(true);
                }
            });

            out.push({
                key: 'download',
                label: 'Download',
                icon: <DownloadOutlined/>,
                extra: 'Ctrl+D',
                onClick: () => onDownload(),
            });

            out.push({ type: 'divider' });

            out.push(
                {
                    key: 'copy',
                    label: 'Copy',
                    icon: <CopyOutlined/>,
                    extra: 'Ctrl+C',
                    onClick: () => onCopy(),
                },
                {
                    key: 'cut',
                    label: 'Cut',
                    icon: <ScissorOutlined/>,
                    extra: 'Ctrl+X',
                    onClick: () => onCut(),
                },
                pasteItem,
                { type: 'divider' }
            );
        }

        // 3. Common destructive and mutation actions (Rename, Delete)
        out.push(
            {
                key: 'rename',
                label: 'Rename',
                icon: <EditOutlined/>,
                extra: 'Ctrl+R',
                onClick: () => onRename(),
            },
            {
                key: 'delete',
                label: 'Delete',
                icon: <DeleteOutlined/>,
                extra: 'Del / ←',
                onClick: () => onDelete(),
            },
        );
        return out;
    }, [row, clipboard, openItem, setSelectedFile, setShowPreview]);

    const onDownload = React.useCallback(() => {
        if (!row) return;
        console.debug("Downloading", row.id);
        api
            .withErrorHandling()
            .fmDownload(row.id);
    }, [row, api]);

    const onCopy = React.useCallback(() => {
        if (!row) return;
        console.debug("Copying", row.id);
        setClipboard({
            action: 'copy',
            item: row,
        });
    }, [row, setClipboard]);

    const handleCopy = React.useCallback(id => {
        api
            .withLoadingMessage({
                key: 'file-copy-loader',
                loadingContent: 'Copying file...',
                successContent: 'File copied successfully',
            })
            .fmCopy(id, selectedFolder.id)
            .then(res => {
                setClipboard(null);
                reloadFiles().then(() => setSelectedFile(res.data));
                setVisibleRename(false);
            });
    }, [api, selectedFolder?.id, setClipboard, reloadFiles, setSelectedFile]);

    const onCut = React.useCallback(() => {
        if (!row) return;
        console.debug("Cutting", row.id);
        setClipboard({
            action: 'cut',
            item: row,
        });
    }, [row, setClipboard]);

    const handleMove = React.useCallback(id => {
        api
            .withLoadingMessage({
                key: 'file-move-loader',
                loadingContent: 'Moving file...',
                successContent: 'File moved successfully',
            })
            .fmMove(id, selectedFolder.id)
            .then(res => {
                setClipboard(null);
                reloadFiles().then(() => setSelectedFile(res.data));
                setVisibleRename(false);
            });
    }, [api, selectedFolder?.id, setClipboard, reloadFiles, setSelectedFile]);

    const onPaste = React.useCallback(() => {
        if (!clipboard) return;
        console.debug("Pasting", clipboard);
        if (clipboard.action === 'copy') {
            handleCopy(clipboard.item.id);
        }
        if (clipboard.action === 'cut') {
            handleMove(clipboard.item.id);
        }
    }, [clipboard, handleCopy, handleMove]);

    const onRename = React.useCallback(() => {
        if (!row) return;
        console.debug("Renaming", row.id);
        formRename.setFieldValue("name", row.title);
        setVisibleRename(true);
    }, [row, formRename]);

    const handleRename = React.useCallback((data) => {
        if (!row) return;
        const backendFunction = row.type === 'folder' ? 'fmRenameFolder' : 'fmRenameFile';
        setConfirmLoadingRename(true);
        api
            .withLoadingMessage({
                key: 'file-rename-loader',
                loadingContent: 'Renaming...',
                successContent: 'Renamed successfully',
            })
            [backendFunction](row.id, data.name)
            .then(res => {
                if (row.type === 'folder') {
                    reloadFolders();
                    reloadFiles();
                } else {
                    reloadFiles().then(() => setSelectedFile(res.data));
                }
                setVisibleRename(false);
            })
            .finally(() => {
                setConfirmLoadingRename(false);
            });
    }, [row, api, reloadFolders, reloadFiles, setSelectedFile]);

    const onDelete = React.useCallback(() => {
        if (!row) return;
        console.debug("Delete", row.id);
        const backendFunction = row.type === 'folder' ? 'fmDeleteFolder' : 'fmDeleteFile';
        const itemType = row.type === 'folder' ? 'folder' : 'file';

        modal.confirm({
            icon: <ExclamationCircleFilled/>,
            title: `Are you sure you want to delete this ${itemType}?`,
            content: <ul>
                <li>{row.title}</li>
            </ul>,
            onOk: () => api
                .withLoadingMessage({
                    key: 'file-delete-loader',
                    loadingContent: `Deleting ${itemType}...`,
                    successContent: `${row.type === 'folder' ? 'Folder' : 'File'} deleted successfully`,
                })
                [backendFunction](row.id)
                .then(res => {
                    if (row.type === 'folder') {
                        reloadFolders();
                        reloadFiles();
                    } else {
                        reloadFiles().then(() => setSelectedFile(res.data));
                    }
                }),
        });
    }, [row, modal, api, reloadFolders, reloadFiles, setSelectedFile]);

    return <>
        {/* Rename Modal */}
        <Modal
            open={visibleRename}
            title={<span>Enter a new name</span>}
            onCancel={() => setVisibleRename(false)}
            destroyOnHidden
            okButtonProps={{ autoFocus: true, htmlType: 'submit' }}
            confirmLoading={confirmLoadingRename}
            modalRender={(dom) => (
                <Form
                    form={formRename}
                    layout="vertical"
                    name="file_rename_modal"
                    clearOnDestroy={true}
                    onFinish={(data) => handleRename(data)}
                >
                    {dom}
                </Form>
            )}
        >
            <Form.Item name="name" rules={[{ required: true, message: 'Please input the name!' }]}>
                <Input/>
            </Form.Item>
        </Modal>

        {/* Action Dropdown Menu */}
        <Dropdown
            menu={{ items }}
            trigger={trigger}
            destroyOnHidden
        >
            {children}
        </Dropdown>
    </>;
}