import BookContents from "../contents/BookContents";
import BookTitle from "./BookTitle";
import BookSettings from "../settings/BookSettings";
import React from "react";

export default function BookHeader({ title, chapter, navigation, navigateTo, search }) {

    return (
        <header className="p-2 border-bottom d-flex flex-row align-items-center">
            <BookContents chapter={chapter} navigation={navigation} navigateTo={navigateTo} search={search}/>
            <BookTitle title={title}/>
            <BookSettings/>
        </header>
    );
}
