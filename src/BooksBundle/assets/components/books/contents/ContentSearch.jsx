import { useBook } from "@BooksBundle/components/books/BookContext";
import { Input, Layout, List, Radio, Space, theme as antdTheme } from "antd";
import Highlighter from "react-highlight-words";
import React from "react";

function ContentSearchItem({ item, searchText }) {
    const { token: { controlItemBgActiveHover } } = antdTheme.useToken();
    const { navigateTo, setContentsDrawerOpen } = useBook();

    const onItemClick = React.useCallback(() => {
        navigateTo(item.cfi);
        setContentsDrawerOpen(false);
    }, [navigateTo, item]);

    return <List.Item
        onClick={onItemClick}
        style={{ cursor: 'pointer' }}
    >
        <List.Item.Meta
            title={item.chapter?.label}
            description={<Highlighter
                highlightStyle={{
                    backgroundColor: controlItemBgActiveHover,
                    borderRadius: '5px',
                    padding: '2px 0',
                }}
                searchWords={[searchText]}
                autoEscape
                textToHighlight={item.excerpt}
            />}
        />
    </List.Item>;
}

export default function ContentSearch() {
    const { token: { colorBgElevated } } = antdTheme.useToken();
    const [searchResults, setSearchResults] = React.useState([]);
    const [searchType, setSearchType] = React.useState('all');
    const [searchText, setSearchText] = React.useState('');
    const { search } = useBook();

    const onSearch = React.useCallback(value => {
        setSearchText(value);
        if (!value) {
            setSearchResults([]);
            return;
        }
        search(value, searchType === 'all')
            .then(res => setSearchResults(res));
    }, [search, searchType]);

    return <Layout style={{ height: '100%' }}>
        <Layout.Header style={{ background: colorBgElevated, padding: '0 16px 16px 16px', height: 'auto', lineHeight: 'normal' }}>
            <Space vertical={true} style={{ width: '100%' }}>
                <Input.Search placeholder="Search" onSearch={onSearch} size="large" allowClear/>
                <Radio.Group
                    value={searchType}
                    onChange={e => setSearchType(e.target.value)}
                    options={[
                        { label: 'All chapters', value: 'all' },
                        { label: 'Current chapter', value: 'chapter' },
                    ]}
                />
            </Space>
        </Layout.Header>
        <Layout.Content style={{ background: colorBgElevated, padding: '0 16px' }}>
            <List
                style={{ height: '100%', overflowY: 'scroll' }}
                size="small"
                bordered
                dataSource={searchResults}
                renderItem={item => <ContentSearchItem key={item.cfi} item={item} searchText={searchText}/>}
                height={'150px'}
            />
        </Layout.Content>
        <Layout.Footer style={{ background: colorBgElevated, padding: '16px' }}>
            <span className="dropdown-header text-secondary py-0 px-2">{searchResults.length} results</span>
        </Layout.Footer>
    </Layout>;

}
