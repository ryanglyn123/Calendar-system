<script setup lang="ts">
import logoBlack from '@/../img/Logoblk.png';
import logoOnly from '@/../img/Logoonly.png';
import logoWhite from '@/../img/Logowhite.png';
</script>

<template>
    <div class="flex w-full items-center justify-start group-data-[collapsible=icon]:justify-center">
        <!-- Wordmark (expanded only). Collapse-hide lives on the wrapper so the
             dark: black/white swap can't override it (both compile to :where(),
             so source order — not intent — would otherwise decide). -->
        <span class="group-data-[collapsible=icon]:hidden">
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
        </span>
        <img
            :src="logoOnly"
            alt="FDCP Calendar"
            class="hidden size-8 object-contain group-data-[collapsible=icon]:block"
        />
    </div>
</template>
