import FileManagerTreeSelect from "@CoreBundle/components/file-manager/FileManagerTreeSelect";
import { useBackendApi } from "@AnimeBundle/components/BackendApiContext";
import { CheckCircleOutlined, CloseCircleOutlined, FolderOpenOutlined, GlobalOutlined } from "@ant-design/icons";
import { Form, Input, List, Modal } from "antd";
import React from "react";

const MAL_ANIME_URL_PATTERN = /^https:\/\/myanimelist\.net\/anime\/(\d+)(?:\/.*)?$/;

function DownloadedListItem({ download, fileExists }) {
    let icon = <CloseCircleOutlined style={{ marginRight: 8 }}/>;
    if (fileExists) {
        icon = <CheckCircleOutlined style={{ marginRight: 8 }}/>;
    }
    return <List.Item>{icon} {download.file}</List.Item>;
}

export default function SeasonFolderAddModal({ open, setOpen }) {
    const [confirmLoading, setConfirmLoading] = React.useState(false);

    const [downloadedLoading, setDownloadedLoading] = React.useState(false);
    const [downloaded, setDownloaded] = React.useState([]);
    const [malId, setMalId] = React.useState(null);

    const [form] = Form.useForm();

    const api = useBackendApi();

    React.useEffect(() => {
        if (!malId) {
            setDownloaded([]);
            return;
        }
        setDownloadedLoading(true);
        api
            .withErrorHandling()
            .seasonsFolder()
            .getDownloads(malId)
            .then(
                res => {
                    setDownloaded(res.data);
                },
                err => {
                    setDownloaded([]);
                }
            )
            .finally(() => setDownloadedLoading(false));
    }, [malId]);

    const onSubmit = React.useCallback(data => {
        setConfirmLoading(true);
        api
            .withLoadingMessage({
                key: 'season-folder-add-loader',
                loadingContent: 'Adding season folder...',
                successContent: 'Season folder added successfully',
            })
            .seasonsFolder()
            .add(data)
            .then(res => {
                setOpen(false);
            })
            .finally(() => setConfirmLoading(false));
    }, []);

    return <Modal
        open={open}
        title={<span>Add season folder</span>}
        onCancel={() => setOpen(false)}
        destroyOnHidden
        okButtonProps={{
            autoFocus: true,
            htmlType: 'submit',
        }}
        confirmLoading={confirmLoading}
        modalRender={(dom) =>
            <Form
                form={form}
                layout="vertical"
                name="add_season_folder_modal"
                clearOnDestroy={true}
                onFinish={data => {
                    // pre-elaborazione prima del submit
                    const match = data.url.match(MAL_ANIME_URL_PATTERN);
                    if (match) {
                        data.id = Number.parseInt(match[1]);
                        delete data.url;
                        onSubmit(data);
                    }
                }}
                onValuesChange={(changedValues, allValues) => {
                    if (changedValues.url === undefined) {
                        return;
                    }
                    if (changedValues.url) {
                        const match = changedValues.url.match(MAL_ANIME_URL_PATTERN);
                        if (match) {
                            setMalId(Number.parseInt(match[1]));
                            return;
                        }
                    }
                    setMalId(null);
                }}>
                {dom}
            </Form>
        }
    >
        <Form.Item label="Url MyAnimeList" name="url" rules={[
            { required: true, message: 'Please input season url.' },
            { pattern: MAL_ANIME_URL_PATTERN, message: 'Invalid season url.' }
        ]}>
            <Input prefix={<GlobalOutlined/>} placeholder="https://myanimelist.net/anime/xxxxx"/>
        </Form.Item>
        <Form.Item
            label="Folder"
            name="folder"
            rules={[{ required: true, message: 'Please input season folder.' }]}
        >
            <FileManagerTreeSelect
                apiUrl={api.fmUrl()}
                prefix={<FolderOpenOutlined/>}
                placeholder="Folder"
                showSearch
                treeLine
                style={{ width: '100%' }}
                styles={{
                    popup: { root: { maxHeight: 400, overflow: 'auto' } },
                }}
            />
        </Form.Item>
            <List style={{ maxHeight: '50vh', overflowY: 'scroll' }}
                size="small"
                bordered
                loading={downloadedLoading}
                dataSource={downloaded}
                renderItem={(item) => <DownloadedListItem download={item.download} fileExists={item.file_exists}/>}
                height={'150px'}
            />
    </Modal>;

}