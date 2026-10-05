import { useBook } from "@BooksBundle/components/books/BookContext";
import { Flex, Input, Layout, List, Radio, theme as antdTheme, Typography } from "antd";
import Highlighter from "react-highlight-words";
import React from "react";

function ContentSearchItem({ item, searchText }) {
    const { token: { controlItemBgActiveHover } } = antdTheme.useToken();
    const { navigateTo, setContentsDrawerOpen } = useBook();

    const onItemClick = React.useCallback(() => {
        navigateTo(item.cfi);
        setContentsDrawerOpen(false);
    }, [navigateTo, setContentsDrawerOpen, item.cfi]);

    return (
        <List.Item
            onClick={onItemClick}
            style={{ cursor: 'pointer' }}
        >
            <List.Item.Meta
                title={item.chapter?.label}
                description={
                    <Highlighter
                        highlightStyle={{
                            backgroundColor: controlItemBgActiveHover,
                            color: 'inherit',
                            borderRadius: '5px',
                            padding: '2px 0',
                        }}
                        searchWords={[searchText]}
                        autoEscape
                        textToHighlight={item.excerpt}
                    />
                }
            />
        </List.Item>
    );
}

export default function ContentSearch() {
    const { token: { colorBgElevated } } = antdTheme.useToken();
    const [searchResults, setSearchResults] = React.useState([]);
    const [searchType, setSearchType] = React.useState('all');
    const [searchText, setSearchText] = React.useState('');
    const [searching, setSearching] = React.useState(false);
    const { search } = useBook();

    // Prevent race conditions between overlapping search requests
    const activeSearchId = React.useRef(0);

    const onSearch = React.useCallback((value) => {
        setSearchText(value);
        if (!value || !value.trim()) {
            setSearchResults([]);
            setSearching(false);
            return;
        }

        const currentId = ++activeSearchId.current;
        setSearching(true);

        search(value.trim(), searchType === 'all')
            .then(res => {
                if (currentId === activeSearchId.current) {
                    setSearchResults(res || []);
                }
            })
            .catch(err => {
                console.error("Search error:", err);
            })
            .finally(() => {
                if (currentId === activeSearchId.current) {
                    setSearching(false);
                }
            });
    }, [search, searchType]);

    return (
        <Layout style={{ height: '100%' }}>
            <Layout.Header style={{ background: colorBgElevated, padding: '0 16px 16px 16px', height: 'auto', lineHeight: 'normal' }}>
                <Flex vertical gap="small" style={{ width: '100%' }}>
                    <Input.Search
                        placeholder="Search"
                        onSearch={onSearch}
                        size="large"
                        allowClear
                        loading={searching}
                    />
                    <Radio.Group
                        value={searchType}
                        onChange={e => {
                            setSearchType(e.target.value);
                            if (searchText) {
                                onSearch(searchText);
                            }
                        }}
                        options={[
                            { label: 'All chapters', value: 'all' },
                            { label: 'Current chapter', value: 'chapter' },
                        ]}
                    />
                </Flex>
            </Layout.Header>
            <Layout.Content style={{ background: colorBgElevated, padding: '0 16px' }}>
                <List
                    style={{ height: '100%', overflowY: 'auto' }}
                    size="small"
                    bordered
                    loading={searching}
                    dataSource={searchResults}
                    renderItem={item => <ContentSearchItem key={item.cfi} item={item} searchText={searchText}/>}
                />
            </Layout.Content>
            <Layout.Footer style={{ background: colorBgElevated, padding: 16 }}>
                <Typography.Text type="secondary">{searchResults.length} results</Typography.Text>
            </Layout.Footer>
        </Layout>
    );
}
