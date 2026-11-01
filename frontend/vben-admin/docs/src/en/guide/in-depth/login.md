# Login

This document explains how to customize the login page of your application.

## Login Page Adjustment

If you want to adjust the title, description, icon, and toolbar of the login page, you can do so by configuring the `props` parameter of the `AuthPageLayout` component.

![login](/guide/login.png)

You just need to configure the `props` parameter of `AuthPageLayout` in `src/router/routes/core.ts` within your application:

```ts {4-8}
 {
    component: AuthPageLayout,
    props: {
      sloganImage: "xxx/xxx.png",
      pageTitle: "开箱即用的大型中后台管理系统",
      pageDescription: "工程化、高性能、跨组件库的前端模版",
      toolbar: true,
      toolbarList: ['color', 'language', 'layout', 'theme'],
    }
    // ...
  },
```

::: tip

If these configurations do not meet your needs, you can implement your own login page. Simply implement your own `AuthPageLayout`.

:::

## Login Form Adjustment

If you want to adjust the content of the login form, you can configure the `AuthenticationLogin` component parameters in `src/views/_core/authentication/login.vue` within your application:

```vue
<AuthenticationLogin
  :loading="authStore.loginLoading"
  @submit="authStore.authLogin"
/>
```

::: details AuthenticationLogin Component Props

```ts
{
  /**
   * 验证码登录路径
   */
  codeLoginPath?: string;
  /**
   * 忘记密码路径
   */
  forgetPasswordPath?: string;

  /**
   * 是否处于加载状态
   */
  loading?: boolean;

  /**
   * 二维码登录路径
   */
  qrCodeLoginPath?: string;

  /**
   * 注册路径
   */
  registerPath?: string;

  /**
   * 是否显示验证码登录
   */
  showCodeLogin?: boolean;
  /**
   * 是否显示忘记密码
   */
  showForgetPassword?: boolean;

  /**
   * 是否显示二维码登录
   */
  showQrcodeLogin?: boolean;

  /**
   * 是否显示注册按钮
   */
  showRegister?: boolean;

  /**
   * 是否显示记住账号
   */
  showRememberMe?: boolean;

  /**
   * 是否显示第三方登录
   */
  showThirdPartyLogin?: boolean;

  /**
   * 登录框副标题
   */
  subTitle?: string;

  /**
   * 登录框标题
   */
  title?: string;
}
```

:::

::: tip

If these configurations do not meet your needs, you can implement your own login form and related login logic.

:::
