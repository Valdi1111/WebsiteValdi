import React from 'react';
import CodeMirror from '@uiw/react-codemirror';
import { oneDark } from '@codemirror/theme-one-dark';
import { languages } from '@codemirror/language-data';
import { App, Button, Modal, Spin, Space } from 'antd';
import { SaveOutlined } from '@ant-design/icons';
import { useFileManager } from '@CoreBundle/components/file-manager/FileManagerContext';
import { getLanguageName } from '@CoreBundle/components/file-manager/editor/codeEditorUtils';

/**
 * Text and code editor modal with dynamic syntax highlighting.
 *
 * @param {boolean} visible
 * @param {(visible: boolean) => void} setVisible
 * @param {{ id: string, title: string, extension: string }} file
 * @returns {React.JSX.Element}
 */
export default function CodeEditorModal({ visible, setVisible, file }) {
    const { api } = useFileManager();
    const { message } = App.useApp();

    const [content, setContent] = React.useState('');
    const [initialContent, setInitialContent] = React.useState('');
    const [loading, setLoading] = React.useState(false);
    const [saving, setSaving] = React.useState(false);
    const [extensions, setExtensions] = React.useState([]);

    const hasChanges = content !== initialContent;

    // Dynamically load the language grammar based on extension/filename
    React.useEffect(() => {
        if (!file) return;

        const langName = getLanguageName(file.extension, file.title);
        if (!langName) {
            setExtensions([]);
            return;
        }

        const langDesc = languages.find(l => l.name.toLowerCase() === langName.toLowerCase());
        if (langDesc) {
            langDesc.load().then(support => {
                setExtensions([support]);
            });
        } else {
            setExtensions([]);
        }
    }, [file?.extension, file?.title]);

    // Fetch raw file text from backend (/text GET)
    React.useEffect(() => {
        if (visible && file?.id) {
            setLoading(true);
            api.fmTextGet(file.id)
                .then(res => {
                    const text = typeof res.data === 'string' ? res.data : (res.data != null ? String(res.data) : '');
                    setContent(text);
                    setInitialContent(text);
                })
                .catch((err) => {
                    console.error("Failed to load file text", err);
                    message.error('Error loading file content');
                    setVisible(false);
                })
                .finally(() => setLoading(false));
        }
    }, [visible, file?.id, api, message, setVisible]);

    // Save updated content to backend (/text POST)
    const handleSave = React.useCallback(() => {
        if (!file?.id || saving) return;

        setSaving(true);
        api.fmTextPost(file.id, content)
            .then(() => {
                setInitialContent(content);
                message.success('File saved successfully');
            })
            .catch(() => {
                message.error('Error saving file');
            })
            .finally(() => setSaving(false));
    }, [file?.id, content, saving, api, message]);

    // Save shortcut: Ctrl+S / Cmd+S
    const handleKeyDown = (e) => {
        if ((e.ctrlKey || e.metaKey) && e.key === 's') {
            e.preventDefault();
            handleSave();
        }
    };

    return (
        <Modal
            open={visible}
            title={
                <Space>
                    <span>{file?.title}</span>
                    {hasChanges && <span style={{ color: '#faad14', fontSize: '12px' }}>(unsaved changes)</span>}
                </Space>
            }
            width="80vw"
            style={{ top: 20 }}
            destroyOnHidden
            afterClose={() => {
                setContent('');
                setInitialContent('');
            }}
            onCancel={() => {
                if (hasChanges && !window.confirm('You have unsaved changes. Are you sure you want to close?')) {
                    return;
                }
                setVisible(false);
            }}
            footer={[
                <Button key="cancel" onClick={() => setVisible(false)}>
                    Close
                </Button>,
                <Button
                    key="save"
                    type="primary"
                    icon={<SaveOutlined />}
                    loading={saving}
                    disabled={!hasChanges}
                    onClick={handleSave}
                >
                    Save (Ctrl+S)
                </Button>
            ]}
        >
            <div
                onKeyDown={handleKeyDown}
                style={{
                    minHeight: '60vh',
                    border: '1px solid #d9d9d9',
                    borderRadius: 4,
                    overflow: 'hidden'
                }}
            >
                {loading ? (
                    <div style={{ display: 'flex', justifyContent: 'center', alignItems: 'center', height: '60vh' }}>
                        <Spin size="large" description="Loading file..." />
                    </div>
                ) : (
                    <CodeMirror
                        value={content}
                        height="60vh"
                        theme={oneDark}
                        extensions={extensions}
                        onChange={(value) => setContent(value)}
                        basicSetup={{
                            lineNumbers: true,
                            highlightActiveLineGutter: true,
                            bracketMatching: true,
                            closeBrackets: true,
                            autocompletion: true,
                            foldGutter: true,
                        }}
                    />
                )}
            </div>
        </Modal>
    );
}
