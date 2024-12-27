import StandardTable from "@CoreBundle/components/StandardTable";
import { getListManga } from "@AnimeBundle/api";
import React from 'react';

export default function TableListManga() {

    return <StandardTable backendFunction={getListManga} />;

}