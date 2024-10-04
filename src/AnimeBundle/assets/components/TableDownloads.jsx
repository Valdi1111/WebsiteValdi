import StandardTable from "@CoreBundle/components/StandardTable";
import { getDownloads } from "@AnimeBundle/api";
import React from 'react';

export default function TableDownloads() {

    return <StandardTable backendFunction={getDownloads} />;

}