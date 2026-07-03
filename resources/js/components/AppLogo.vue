<script setup lang="ts">
import logoBlack from '@/../img/Logoblk.png';
import logoOnly from '@/../img/Logoonly.png';
import logoWhite from '@/../img/Logowhite.png';
</script>

<template>
    <div class="flex w-full items-center justify-start">
        <img
            :src="logoBlack"
            alt="FDCP Calendar"
            class="h-8 w-auto object-contain group-data-[collapsible=icon]:hidden dark:hidden"
        />
        <img
            :src="logoWhite"
            alt="FDCP Calendar"
            class="hidden h-8 w-auto object-contain group-data-[collapsible=icon]:hidden dark:block"
        />
        <img
            :src="logoOnly"
            alt="FDCP Calendar"
            class="hidden size-8 object-contain group-data-[collapsible=icon]:block"
        />
    </div>
</template>
