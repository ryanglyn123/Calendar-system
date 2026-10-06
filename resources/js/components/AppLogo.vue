<script setup lang="ts">
import logoBlack from '@/../img/Logoblk.png';
import logoWhite from '@/../img/Logowhite.png';
</script>

<template>
    <!-- Wordmark. The sidebar's icon-only mark lives in AppSidebar, where it
         crossfades with this one as the rail opens and closes. -->
    <div class="flex w-full items-center justify-start">
        <img
            :src="logoBlack"
            alt="FDCP Calendar"
            class="h-8 w-auto object-contain dark:hidden"
        />
        <img
            :src="logoWhite"
            alt="FDCP Calendar"
            class="hidden h-8 w-auto object-contain dark:block"
        />
    </div>
</template>
