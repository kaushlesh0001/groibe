{{-- PDF only (spec 10.3, 8): the static Inter Regular and Bold files from resources/fonts/pdf. dompdf cannot read the web's variable
     font, and it has no network access, so the files are read from disk and cached in storage/fonts on first use. --}}
@font-face {
    font-family: 'Inter';
    font-style: normal;
    font-weight: 400;
    src: url('{{ str_replace('\\', '/', resource_path('fonts/pdf/Inter-Regular.ttf')) }}') format('truetype');
}
@font-face {
    font-family: 'Inter';
    font-style: normal;
    font-weight: 700;
    src: url('{{ str_replace('\\', '/', resource_path('fonts/pdf/Inter-Bold.ttf')) }}') format('truetype');
}
